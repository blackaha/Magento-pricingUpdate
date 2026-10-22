<?php
/**
 * Finds the one product an update is for.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Blackaha\PricingUpdater\Exception\HttpException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * SKU to exactly one product that has a price of its own.
 */
class Resolver
{
    /** Product types whose price is their own: simple, virtual, downloadable. */
    private const PRICED_TYPES = [
        ProductType::TYPE_SIMPLE,
        ProductType::TYPE_VIRTUAL,
        'downloadable',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * Resolves a SKU.
     *
     * Never picks one of several matches, and never targets a configurable, bundle
     * or grouped product: their price is that of their children.
     *
     * @param string $sku SKU to look for.
     * @param int $storeId Store view the product is loaded in.
     * @return ProductInterface|array The product, or a failed result explaining why there is none.
     * @throws HttpException On database failure.
     */
    public function resolve(string $sku, int $storeId): ProductInterface|array
    {
        // Magento keeps SKUs unique on save only; an import can still leave duplicates,
        // which must be reported rather than resolved by picking one.
        try {
            $connection = $this->resource->getConnection();
            $ids = $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                    ->where('sku = ?', $sku)
                    ->limit(100)
            );
        } catch (\Throwable $e) {
            throw new HttpException(503, 'storage_unavailable', 'The product catalog could not be read.');
        }

        $count = count($ids);

        if (0 === $count) {
            return Protocol::failed((string) __('No product with SKU "%1" was found in the store.', $sku));
        }

        if ($count > 1) {
            return Protocol::failed(
                (string) __(
                    'SKU "%1" is used by %2 products in the store. It must identify exactly one, so the price was not changed.',
                    $sku,
                    $count
                )
            );
        }

        $reference = 'm2:' . (int) $ids[0];

        try {
            $product = $this->productRepository->getById((int) $ids[0], false, $storeId, true);
        } catch (NoSuchEntityException $e) {
            return Protocol::failed(
                (string) __('The product with SKU "%1" could not be loaded.', $sku),
                $reference
            );
        }

        $type = (string) $product->getTypeId();

        if (in_array($type, ['configurable', 'bundle', 'grouped'], true)) {
            return Protocol::failed(
                (string) __(
                    'SKU "%1" belongs to a %2 product, which has no price of its own. Use the SKU of one of its simple products.',
                    $sku,
                    $type
                ),
                $reference
            );
        }

        if (!in_array($type, self::PRICED_TYPES, true)) {
            return Protocol::failed(
                (string) __(
                    'The product with SKU "%1" is of type "%2". Only simple, virtual and downloadable products can be updated.',
                    $sku,
                    $type
                ),
                $reference
            );
        }

        return $product;
    }
}
