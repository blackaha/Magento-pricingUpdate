<?php
/**
 * Where prices are written and read, and in which currency.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Settles exactly one price scope, following Catalog > Price > Catalog Price Scope.
 *
 * Global: prices are written on the default values (store 0) and checked on the
 * default store view. Website: prices are written on one chosen website, never
 * on all of them at once, and checked on its default store view.
 */
class PriceScope
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Settings $settings
    ) {
    }

    /**
     * The scope prices are applied in.
     *
     * @return array{write_store_id: int, read_store_id: int, website_id: int, website_name: string, currency: string, error: ?string}
     */
    public function resolve(): array
    {
        $scope = [
            'write_store_id' => Store::DEFAULT_STORE_ID,
            'read_store_id' => 0,
            'website_id' => 0,
            'website_name' => '',
            'currency' => '',
            'error' => null,
        ];

        if (!$this->isPerWebsite()) {
            $store = $this->storeManager->getDefaultStoreView();

            $scope['read_store_id'] = $store ? (int) $store->getId() : 0;
            $scope['currency'] = strtoupper((string) $this->scopeConfig->getValue(Currency::XML_PATH_CURRENCY_BASE));

            return $scope;
        }

        $websiteId = $this->settings->getWebsiteId();
        $websites = $this->storeManager->getWebsites();

        // With a single website there is nothing to choose.
        if (0 === $websiteId && 1 === count($websites)) {
            $websiteId = (int) reset($websites)->getId();
        }

        $website = $websiteId ? ($websites[$websiteId] ?? null) : null;
        $store = $website ? $website->getDefaultStore() : null;

        if (!$website || !$store) {
            $scope['error'] = (string) __(
                'The store sets prices per website, and no website is chosen for price updates in Stores > Configuration > Catalog > Blackaha Pricing Updater, so the price was not changed.'
            );

            return $scope;
        }

        $scope['write_store_id'] = (int) $store->getId();
        $scope['read_store_id'] = (int) $store->getId();
        $scope['website_id'] = (int) $website->getId();
        $scope['website_name'] = (string) $website->getName();
        $scope['currency'] = strtoupper((string) $website->getBaseCurrencyCode());

        return $scope;
    }

    public function isPerWebsite(): bool
    {
        return Store::PRICE_SCOPE_WEBSITE === (int) $this->scopeConfig->getValue(Store::XML_PATH_PRICE_SCOPE);
    }
}
