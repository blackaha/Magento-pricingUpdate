<?php
/**
 * Stored settings: the signing secret, the webhook slug and the state of the connection.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Math\Random;

/**
 * Reads and writes the module's state.
 *
 * The connection lives in the flag table rather than core_config_data: it changes
 * at runtime (every genuine request updates it) and must be read fresh by the
 * webhook, without waiting for the configuration cache to be cleaned. The secret
 * is encrypted with the installation's key.
 */
class Settings
{
    public const FLAG_SECRET = 'blackaha_pricing_updater_secret';
    public const FLAG_SLUG = 'blackaha_pricing_updater_webhook_slug';
    public const FLAG_CONNECTION = 'blackaha_pricing_updater_connection';
    public const FLAG_ADMIN_URL = 'blackaha_pricing_updater_admin_url';

    public const XML_PATH_WEBSITE_ID = 'blackaha_pricing_updater/general/website_id';
    public const XML_PATH_RETENTION_DAYS = 'blackaha_pricing_updater/general/ledger_retention_days';

    /** env.php key that overrides the pricing service address, for staging and development. */
    public const ENV_SERVICE_URL = 'blackaha_pricing_updater/service_url';

    public const DEFAULT_SERVICE_URL = 'https://app.blackaha.com';
    public const SERVICE_NAME = 'BlackAha';

    /** Largest clock difference with the pricing service that is accepted, in seconds. */
    public const MAX_CLOCK_OFFSET = 86400;

    /** Length of the random part of the webhook URL. */
    public const SLUG_LENGTH = 32;

    private const ALPHANUMERIC = Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS;

    public function __construct(
        private readonly FlagManager $flags,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * The signing secret, '' when none is saved.
     */
    public function getSecret(): string
    {
        $stored = $this->flags->getFlagData(self::FLAG_SECRET);

        if (!is_string($stored) || '' === $stored) {
            return '';
        }

        $secret = $this->encryptor->decrypt($stored);

        return is_string($secret) ? $secret : '';
    }

    /**
     * Whether the value can be a signing secret: printable, no spaces, long enough.
     */
    public function isValidSecret(string $secret): bool
    {
        return 1 === preg_match('/\A[\x21-\x7E]{16,255}\z/', $secret);
    }

    /**
     * Saves a secret entered by hand. A new secret is a new connection, so what was
     * known about the previous one is forgotten.
     */
    public function saveSecret(string $secret): void
    {
        $this->flags->saveFlag(self::FLAG_SECRET, $this->encryptor->encrypt($secret));
        $this->flags->deleteFlag(self::FLAG_CONNECTION);
    }

    /**
     * Saves a connection established through the pricing service. The slug changes
     * with every connection, so the previous webhook URL stops answering at once.
     */
    public function saveConnection(
        string $slug,
        string $secret,
        string $workspaceId,
        string $service,
        int $clockOffset,
        int $now
    ): void {
        $this->flags->saveFlag(self::FLAG_SLUG, $slug);
        $this->flags->saveFlag(self::FLAG_SECRET, $this->encryptor->encrypt($secret));
        $this->flags->saveFlag(
            self::FLAG_CONNECTION,
            [
                'workspace_id' => $workspaceId,
                'service_url' => $service,
                'clock_offset' => $this->boundClockOffset($clockOffset),
                'connected_at' => $now,
                'last_test_at' => 0,
                'last_update_at' => 0,
            ]
        );
    }

    /**
     * Removes the secret; every request is refused until a new connection is made.
     */
    public function deleteSecret(): void
    {
        $this->flags->deleteFlag(self::FLAG_SECRET);
        $this->flags->deleteFlag(self::FLAG_CONNECTION);
    }

    /**
     * The stored webhook slug, '' when there is none yet. Never creates one: the
     * webhook must not invent the URL it is called on.
     */
    public function getStoredSlug(): string
    {
        $slug = $this->flags->getFlagData(self::FLAG_SLUG);

        return is_string($slug) && $this->isValidSlug($slug) ? $slug : '';
    }

    /**
     * The webhook slug, created on first use.
     */
    public function getWebhookSlug(): string
    {
        $slug = $this->getStoredSlug();

        if ('' === $slug) {
            $slug = $this->generateSlug();
            $this->flags->saveFlag(self::FLAG_SLUG, $slug);
        }

        return $slug;
    }

    public function generateSlug(): string
    {
        return $this->random->getRandomString(self::SLUG_LENGTH, self::ALPHANUMERIC);
    }

    /**
     * A random alphanumeric string, for states and ids.
     */
    public function randomToken(int $length): string
    {
        return $this->random->getRandomString($length, self::ALPHANUMERIC);
    }

    public function isValidSlug(string $slug): bool
    {
        return 1 === preg_match('/\A[A-Za-z0-9]{' . self::SLUG_LENGTH . '}\z/', $slug);
    }

    /**
     * State of the connection.
     *
     * @return array{workspace_id: string, service_url: string, clock_offset: int, connected_at: int, last_test_at: int, last_update_at: int}
     */
    public function getConnection(): array
    {
        $connection = $this->flags->getFlagData(self::FLAG_CONNECTION);

        return array_merge(
            [
                'workspace_id' => '',
                'service_url' => '',
                'clock_offset' => 0,
                'connected_at' => 0,
                'last_test_at' => 0,
                'last_update_at' => 0,
            ],
            is_array($connection) ? $connection : []
        );
    }

    /**
     * This server's time read on the pricing service's clock.
     */
    public function serviceTime(): int
    {
        return time() - $this->boundClockOffset($this->getConnection()['clock_offset']);
    }

    /**
     * A clock offset within the accepted range; anything else counts as none.
     */
    public function boundClockOffset(mixed $offset): int
    {
        return is_int($offset) && abs($offset) <= self::MAX_CLOCK_OFFSET ? $offset : 0;
    }

    /**
     * Whether the connection has been confirmed by at least one genuine request.
     */
    public function isVerified(): bool
    {
        $connection = $this->getConnection();

        return '' !== $this->getSecret() && ($connection['last_test_at'] || $connection['last_update_at']);
    }

    /**
     * Records a genuine request: keeps the workspace_id of the first one, so that
     * later requests must carry the same, and remembers when it came in.
     */
    public function recordRequest(string $workspaceId, string $type, int $now): void
    {
        $connection = $this->getConnection();

        if ('' === $connection['workspace_id']) {
            $connection['workspace_id'] = $workspaceId;
        }

        if (Protocol::TYPE_TEST === $type) {
            $connection['last_test_at'] = $now;
        } else {
            $connection['last_update_at'] = $now;
        }

        $this->flags->saveFlag(self::FLAG_CONNECTION, $connection);
    }

    /**
     * The admin page the browser returns to after the pricing service, remembered
     * for when a callback arrives without a known attempt.
     */
    public function getAdminPageUrl(): string
    {
        $url = $this->flags->getFlagData(self::FLAG_ADMIN_URL);

        return is_string($url) ? $url : '';
    }

    public function saveAdminPageUrl(string $url): void
    {
        if ($url !== $this->getAdminPageUrl()) {
            $this->flags->saveFlag(self::FLAG_ADMIN_URL, $url);
        }
    }

    /**
     * Website whose prices are updated when prices are set per website; 0 when none is chosen.
     */
    public function getWebsiteId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_WEBSITE_ID);
    }

    public function getRetentionDays(): int
    {
        return max(1, (int) ($this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS) ?: 30));
    }

    /**
     * The pricing service base URL as configured, unvalidated.
     */
    public function getServiceUrl(): string
    {
        $url = $this->deploymentConfig->get(self::ENV_SERVICE_URL);

        return is_string($url) && '' !== $url ? $url : self::DEFAULT_SERVICE_URL;
    }
}
