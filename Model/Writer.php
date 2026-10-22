<?php
/**
 * Sets a product's price and confirms what customers now pay.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Blackaha\PricingUpdater\Exception\HttpException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the price of a product.
 *
 * "Applied" means the price a guest pays (before tax, for one unit) is the
 * requested price at the time of answering. The writer never converts currencies,
 * never rounds, and never reports success on anything but a price read back from
 * the store.
 *
 * A product without a running special price gets the new price as its price. A
 * product with one keeps its price and gets the new price as its special price
 * when it is below the price; a new price at or above the price replaces the
 * price and ends the special price.
 */
class Writer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductAction $productAction,
        private readonly PriceIndexer $priceIndexer,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly TimezoneInterface $localeDate,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Applies a price.
     *
     * @param Product $product Product loaded in the scope's read store.
     * @param array $item Validated item: update_id, sku, target_price, currency.
     * @param array $scope Price scope, see PriceScope::resolve().
     * @return array Applied or failed result.
     * @throws HttpException When the outcome is unknown and must not be recorded.
     */
    public function apply(Product $product, array $item, array $scope): array
    {
        $productId = (int) $product->getId();
        $reference = 'm2:' . $productId;
        $target = $item['target_price'];
        $price = Protocol::numberToString($target);

        if (null !== $scope['error']) {
            return Protocol::failed($scope['error'], $reference);
        }

        $storeCurrency = $scope['currency'];
        $currency = null === $item['currency'] ? '' : strtoupper(trim($item['currency']));

        if ('' === $currency) {
            return Protocol::failed(
                (string) __('The update did not say which currency the price is in, so the price was not changed.'),
                $reference
            );
        }

        if ($currency !== $storeCurrency) {
            return Protocol::failed(
                (string) __(
                    'The price is in %1 but the store\'s base currency is %2. Prices are never converted automatically, so the price was not changed.',
                    $currency,
                    $storeCurrency
                ),
                $reference
            );
        }

        if ($target < 0) {
            return Protocol::failed((string) __('A price cannot be negative, so the price was not changed.'), $reference);
        }

        $decimals = PriceCurrencyInterface::DEFAULT_PRECISION;
        if (abs(round($target, $decimals) - $target) > 0.0000001) {
            return Protocol::failed(
                (string) __(
                    'The price %1 has more decimals than the store shows (%2). Prices are never rounded automatically, so the price was not changed.',
                    $price,
                    $decimals
                ),
                $reference
            );
        }

        // Lets another module protect a product: set "allowed" to false, and "reason" to say why.
        $transport = new DataObject(['allowed' => true, 'reason' => '']);
        $this->eventManager->dispatch(
            'blackaha_pricing_updater_allow_price_update',
            ['transport' => $transport, 'product' => $product, 'item' => $item]
        );

        if (true !== $transport->getData('allowed')) {
            $reason = (string) $transport->getData('reason');

            return Protocol::failed(
                '' !== $reason
                    ? $reason
                    : (string) __('This product is protected from automatic price updates by the store, so the price was not changed.'),
                $reference
            );
        }

        if ($this->sellsAt($product, $target)) {
            return Protocol::applied($target, $reference);
        }

        $specialActive = $this->hasActiveSpecialPrice($product, $scope['read_store_id']);

        if ($specialActive && $target < (float) $product->getData('price')) {
            // A running special price and a price below the regular one: the special price becomes
            // the new price, the regular price stays, so the product still shows as discounted.
            $data = ['special_price' => $price];
        } else {
            // A price at or above the regular one ends a running special price: customers must see exactly this price.
            $data = ['price' => $price];
            if ($specialActive) {
                $data['special_price'] = null;
            }
        }

        try {
            $this->productAction->updateAttributes([$productId], $data, $scope['write_store_id']);
        } catch (\Throwable $e) {
            // The write is rolled back or unknown: answered with a server error, never recorded.
            $this->logger->error('Price write failed for product ' . $productId . ': ' . $e->getMessage());
            throw new HttpException(500, 'write_failed', 'The price could not be saved.');
        }

        $this->refresh($productId);

        try {
            /** @var Product $saved */
            $saved = $this->productRepository->getById($productId, false, $scope['read_store_id'], true);
        } catch (\Throwable $e) {
            throw new HttpException(500, 'write_unconfirmed', 'The price could not be confirmed after saving.');
        }

        if ($this->sellsAt($saved, $target)) {
            $this->eventManager->dispatch(
                'blackaha_pricing_updater_price_applied',
                ['product' => $saved, 'price' => $target, 'item' => $item]
            );

            return Protocol::applied($target, $reference);
        }

        $effective = $this->effectivePrice($saved);

        return Protocol::failed(
            (string) __(
                'The store did not keep the new price: %1 was saved but the product sells at %2. A catalog price rule, a tier price or another extension may be changing it.',
                $price,
                null === $effective ? '-' : Protocol::numberToString(Protocol::normalizeNumber($effective))
            ),
            $reference
        );
    }

    /**
     * Whether the price a guest pays for one unit is the given amount.
     */
    private function sellsAt(Product $product, int|float $amount): bool
    {
        $effective = $this->effectivePrice($product);

        return null !== $effective && Protocol::samePrice($effective, $amount);
    }

    /**
     * The price a guest pays for one unit, in base currency, before tax: the lowest of
     * price, special price and tier price, after catalog price rules. Null when the
     * product has no price.
     */
    private function effectivePrice(Product $product): ?float
    {
        $price = $product->getData('price');
        if (null === $price || '' === $price) {
            return null;
        }

        $product->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);

        return (float) $product->getPriceModel()->getFinalPrice(1, $product);
    }

    /**
     * Whether a special price is set and its dates include today.
     */
    private function hasActiveSpecialPrice(Product $product, int $storeId): bool
    {
        $special = $product->getData('special_price');

        if (null === $special || '' === $special) {
            return false;
        }

        return $this->localeDate->isScopeDateInInterval(
            $storeId,
            $product->getData('special_from_date'),
            $product->getData('special_to_date')
        );
    }

    /**
     * Brings the price index and the page caches up to date. With the indexer on
     * "Update by Schedule" the index follows on the next cron run instead.
     */
    private function refresh(int $productId): void
    {
        try {
            $this->priceIndexer->reindexRow($productId);
        } catch (\Throwable $e) {
            // The price is saved; the index catches up on the next run.
            $this->logger->warning('Price reindex failed for product ' . $productId . ': ' . $e->getMessage());
        }

        $this->cacheContext->registerEntities(Product::CACHE_TAG, [$productId]);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }
}
