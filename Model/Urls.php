<?php
/**
 * Addresses of this store, as the pricing service sees them.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Model;

use Magento\Framework\App\State;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the public endpoints of the module on the default store view and checks
 * that the pricing service can reach them.
 *
 * Every URL is built from the default store view's secure link URL, so that it
 * carries the store code and index.php exactly when the storefront does, and is
 * the final address: the pricing service never follows a redirect.
 */
class Urls
{
    public const ROUTE_WEBHOOK = 'dynamicpricing/webhook/index/slug/';
    public const ROUTE_VERIFY = 'dynamicpricing/connect/verify';
    public const ROUTE_CALLBACK = 'dynamicpricing/connect/callback';

    /** Host suffixes that never denote a public site. */
    private const INTERNAL_SUFFIXES = [
        '.localhost', '.local', '.internal', '.lan', '.home.arpa', '.test', '.invalid', '.example',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState
    ) {
    }

    /**
     * The address the site is served on, without a trailing slash.
     */
    public function siteUrl(): string
    {
        return rtrim((string) $this->defaultStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
    }

    public function webhookUrl(string $slug): string
    {
        return $this->linkBase() . self::ROUTE_WEBHOOK . $slug;
    }

    public function verifyUrl(): string
    {
        return $this->linkBase() . self::ROUTE_VERIFY;
    }

    public function callbackUrl(): string
    {
        return $this->linkBase() . self::ROUTE_CALLBACK;
    }

    /**
     * Whether the site can be connected: a public HTTPS address, with every endpoint
     * on that same host. Returns the problem to show, or null when there is none.
     */
    public function siteProblem(): ?string
    {
        $site = $this->origin($this->siteUrl());

        if (null === $site) {
            return 'site_not_public';
        }

        if (!$this->isLocalEnvironment()) {
            $parts = parse_url($site);

            if ('https' !== $parts['scheme'] || !$this->isPublicHost($parts['host'])) {
                return 'site_not_public';
            }
        }

        $endpoints = [$this->callbackUrl(), $this->verifyUrl(), $this->webhookUrl(str_repeat('x', Settings::SLUG_LENGTH))];
        foreach ($endpoints as $url) {
            if ($this->origin($url) !== $site) {
                return 'site_hosts_differ';
            }
        }

        return null;
    }

    /**
     * Scheme, lowercase host and port of a URL, or null when it has none.
     */
    public function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['https', 'http'], true)
        ) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * Whether the host is a public domain name: no IP address, no single-label or
     * reserved internal name.
     */
    public function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (false !== filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.') || 'localhost' === $host) {
            return false;
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether Magento runs in developer mode, where plain HTTP and internal
     * addresses are allowed for testing.
     */
    public function isLocalEnvironment(): bool
    {
        return State::MODE_DEVELOPER === $this->appState->getMode();
    }

    private function linkBase(): string
    {
        return rtrim((string) $this->defaultStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/') . '/';
    }

    private function defaultStore(): \Magento\Store\Model\Store
    {
        return $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
    }
}
