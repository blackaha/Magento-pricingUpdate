<?php
/**
 * The Pricing Updater admin page.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Block\Adminhtml;

use BlackAha\DynamicPricing\Model\Connector;
use BlackAha\DynamicPricing\Model\Ledger;
use BlackAha\DynamicPricing\Model\PriceScope;
use BlackAha\DynamicPricing\Model\Protocol;
use BlackAha\DynamicPricing\Model\Settings;
use BlackAha\DynamicPricing\Model\Urls;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * One click to connect, one card to show the state of the connection, the manual
 * way as a fallback, and the latest results.
 */
class Connection extends Template
{
    /**
     * @var string
     */
    protected $_template = 'BlackAha_DynamicPricing::connection.phtml';

    private ?array $scope = null;

    public function __construct(
        Context $context,
        private readonly Settings $settings,
        private readonly Connector $connector,
        private readonly Ledger $ledger,
        private readonly PriceScope $priceScope,
        private readonly Urls $urls,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * One of: disconnected, configured (a secret is stored, no signed request
     * received yet), connected.
     */
    public function getState(): string
    {
        if ('' === $this->settings->getSecret()) {
            return 'disconnected';
        }

        return $this->settings->isVerified() ? 'connected' : 'configured';
    }

    public function getConnection(): array
    {
        return $this->settings->getConnection();
    }

    public function hasSecret(): bool
    {
        return '' !== $this->settings->getSecret();
    }

    public function getServiceName(): string
    {
        return Settings::SERVICE_NAME;
    }

    public function getWebhookUrl(): string
    {
        return $this->urls->webhookUrl($this->settings->getWebhookSlug());
    }

    public function getSiteProblem(): ?string
    {
        return $this->connector->siteProblem();
    }

    /**
     * @see PriceScope::resolve()
     */
    public function getScope(): array
    {
        return $this->scope ??= $this->priceScope->resolve();
    }

    /**
     * Address of the store connection settings on the pricing service, '' when unknown.
     */
    public function getServiceSettingsUrl(): string
    {
        $service = $this->getConnection()['service_url'] ?: $this->connector->serviceOriginOrEmpty();

        return '' !== $service ? $service . '/settings/pricing-rules' : '';
    }

    /**
     * Address of the manual store connection setup on the pricing service, '' when unknown.
     */
    public function getManualUrl(): string
    {
        $service = $this->connector->serviceOriginOrEmpty();

        return '' !== $service ? $service . '/settings/pricing-rules?action=store-connection' : '';
    }

    public function getActionUrl(string $action): string
    {
        return $this->getUrl('dynamicpricing/connection/' . $action);
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'blackaha_dynamic_pricing']);
    }

    /**
     * Whether the last action was a failed or cancelled connection.
     */
    public function connectWentWrong(): bool
    {
        return in_array($this->noticeKey(), ['connect_failed', 'connect_cancelled'], true);
    }

    /**
     * The outcome of the last action: [type, message], or null.
     */
    public function getNotice(): ?array
    {
        return match ($this->noticeKey()) {
            'connect_cancelled' => [
                'warning',
                __('Connection cancelled. Nothing was changed. You can start again, or connect manually below.'),
            ],
            'disconnected' => ['success', __('Disconnected. The store no longer accepts price updates.')],
            'invalid' => [
                'error',
                __('That does not look like a signing secret. Paste it exactly as it was shown to you, without spaces.'),
            ],
            'connect_failed' => [
                'error',
                $this->connector->takeError((string) $this->getRequest()->getParam('dynamic_pricing_error'))
                    ?: __('The connection could not be completed. Please start again, or connect manually below.'),
            ],
            default => null,
        };
    }

    private function noticeKey(): string
    {
        return (string) $this->getRequest()->getParam('dynamic_pricing_notice');
    }

    /**
     * A Unix time in the admin's locale and the store's timezone.
     */
    public function formatTimestamp(int $timestamp): string
    {
        return $this->_localeDate->formatDateTime(
            new \DateTime('@' . $timestamp),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * A UTC "Y-m-d H:i:s" value of the ledger, formatted like formatTimestamp().
     */
    public function formatUtc(string $datetime): string
    {
        $time = strtotime($datetime . ' UTC');

        return false === $time ? $datetime : $this->formatTimestamp($time);
    }

    public function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $this->getScope()['currency'] ?: null
        );
    }

    /**
     * @return array[]
     */
    public function getRecent(): array
    {
        try {
            return $this->ledger->recent(20);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isApplied(array $row): bool
    {
        return Protocol::STATUS_APPLIED === $row['status'];
    }

    /**
     * Edit link of the product a result refers to, '' when there is none.
     *
     * @param string $reference Reference stored with the result, "m2:<id>".
     */
    public function getProductEditUrl(string $reference): string
    {
        if (1 !== preg_match('/\Am2:(\d+)\z/', $reference, $matches)) {
            return '';
        }

        return $this->getUrl('catalog/product/edit', ['id' => (int) $matches[1]]);
    }
}
