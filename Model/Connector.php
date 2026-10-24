<?php
/**
 * One-click connection to the pricing service.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Model;

use BlackAha\DynamicPricing\Exception\ConnectException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Connects the store without copying anything by hand.
 *
 * The administrator clicks Connect, approves the connection in their account on
 * the pricing service and is brought back here. Behind that, this class opens a
 * connection attempt with the service, proves to it that this site is the one
 * asking, and exchanges the approval for the signing secret used by the webhook.
 * Everything secret about an attempt is created at runtime, kept on the server
 * only, and lives at most fifteen minutes; nothing is shared between
 * installations of the module.
 */
class Connector
{
    public const CLIENT_ID = 'magento';

    private const CACHE_ATTEMPT = 'blackaha_dp_ca_';
    private const CACHE_USER = 'blackaha_dp_cu_';
    private const CACHE_ERROR = 'blackaha_dp_ce_';
    private const CACHE_TAG = 'BLACKAHA_DYNAMIC_PRICING';
    private const ATTEMPT_TTL = 900;
    private const ERROR_TTL = 600;
    private const HTTP_TIMEOUT = 15;

    /** Largest answer accepted from the pricing service, in bytes. */
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private readonly Settings $settings,
        private readonly Urls $urls,
        private readonly CacheInterface $cache,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The pricing service base URL as an origin: https, host, optional port, no path.
     *
     * @throws ConnectException When the configured address is not one.
     */
    public function serviceOrigin(): string
    {
        $url = $this->settings->getServiceUrl();
        $origin = $this->urls->origin($url);
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (null === $origin
            || '' !== $path
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || ('https' !== strtolower($parts['scheme']) && !$this->urls->isLocalEnvironment())
        ) {
            throw new ConnectException(
                'bad_service_url',
                (string) __('The module is not configured with a valid pricing service address.')
            );
        }

        return $origin;
    }

    /**
     * The pricing service origin, or '' when it is misconfigured.
     */
    public function serviceOriginOrEmpty(): string
    {
        try {
            return $this->serviceOrigin();
        } catch (ConnectException $e) {
            return '';
        }
    }

    /**
     * Checks, before contacting the pricing service, that this site can be connected.
     *
     * @return string|null The problem to show, or null when there is none.
     */
    public function siteProblem(): ?string
    {
        return match ($this->urls->siteProblem()) {
            null => null,
            'site_hosts_differ' => (string) __(
                'The base URL and the base link URL of the default store view use different domains or protocols. Set both secure URLs to the same HTTPS address, then try again.'
            ),
            default => $this->siteNotPublicMessage(),
        };
    }

    /**
     * Opens a connection attempt and returns the URL the administrator must be sent
     * to in order to approve it.
     *
     * @param int $userId Administrator starting the attempt.
     * @param string $returnUrl Admin page the browser comes back to.
     * @throws ConnectException When the attempt cannot be opened.
     */
    public function start(int $userId, string $returnUrl): string
    {
        $service = $this->serviceOrigin();

        $problem = $this->siteProblem();
        if (null !== $problem) {
            throw new ConnectException('site_not_public', $problem);
        }

        $this->cancel($userId);

        [$status, $data] = $this->post(
            $service,
            'init',
            '/api/pricing-connect/init',
            [
                'client_id' => self::CLIENT_ID,
                'site_url' => $this->urls->siteUrl(),
                'callback_url' => $this->urls->callbackUrl(),
                'verify_url' => $this->urls->verifyUrl(),
                // Lets the service allow for this server's clock being off.
                'client_time' => time(),
            ]
        );

        if (201 !== $status) {
            throw $this->serviceError('init', $status, $data);
        }

        $connectId = $data['connect_id'] ?? null;
        $connectSecret = $data['connect_secret'] ?? null;
        $consentUrl = $data['consent_url'] ?? null;

        if (!Protocol::isConnectId($connectId)
            || !is_string($connectSecret) || 1 !== preg_match('/\Apcs_[\x21-\x7E]{16,252}\z/', $connectSecret)
            || !is_string($consentUrl) || strlen($consentUrl) > 2000
        ) {
            throw $this->unexpectedAnswer('init');
        }

        // The approval link must open on the same service the attempt was opened on.
        if ($this->urls->origin($consentUrl) !== $service) {
            $this->log('init: approval link on another origin');
            throw new ConnectException(
                'origin_mismatch',
                (string) __('The pricing service answered from an unexpected address. Please contact support.')
            );
        }

        $attempt = [
            'connect_id' => $connectId,
            'connect_secret' => $connectSecret,
            'consent_url' => $consentUrl,
            'state' => $this->settings->randomToken(32),
            'user_id' => $userId,
            'service' => $service,
            'return_url' => $returnUrl,
        ];

        $this->save($this->attemptKey($connectId), $attempt, self::ATTEMPT_TTL);
        $this->save(self::CACHE_USER . $userId, $connectId, self::ATTEMPT_TTL);

        return $this->consentLink($attempt);
    }

    /**
     * Finishes an approved attempt: exchanges the approval for the signing secret
     * and stores the new connection, under a new webhook URL.
     *
     * @throws ConnectException When the connection cannot be completed.
     */
    public function complete(string $connectId, string $state, string $code): void
    {
        $attempt = $this->ownedAttempt($connectId, $state);

        // From here on the attempt ends, whatever the outcome.
        $this->end($attempt);

        if ($this->serviceOriginOrEmpty() !== $attempt['service']) {
            throw new ConnectException(
                'service_changed',
                (string) __('The pricing service address changed during the connection. Please start again.')
            );
        }

        if (!Protocol::isConnectCode($code)) {
            throw new ConnectException('bad_code', (string) __('The approval received is not valid. Please start again.'));
        }

        $slug = $this->settings->generateSlug();
        $result = $this->exchange($attempt, $code, $this->urls->webhookUrl($slug));

        $this->settings->saveConnection(
            $slug,
            $result['signing_secret'],
            $result['workspace_id'],
            $attempt['service'],
            $result['clock_offset'],
            time()
        );
    }

    /**
     * Ends an attempt the administrator declined on the pricing service. The
     * request must carry the attempt's state, so that nobody else can cancel it.
     *
     * @throws ConnectException When the attempt is unknown or not this browser's.
     */
    public function decline(string $connectId, string $state): void
    {
        $this->end($this->ownedAttempt($connectId, $state));
    }

    /**
     * Forgets the administrator's pending attempt, if any.
     */
    public function cancel(int $userId): void
    {
        $connectId = $this->load(self::CACHE_USER . $userId);
        $attempt = is_string($connectId) ? $this->attempt($connectId) : null;

        if (null !== $attempt && (int) $attempt['user_id'] === $userId) {
            $this->end($attempt);
        }

        $this->cache->remove(self::CACHE_USER . $userId);
    }

    /**
     * The admin page an attempt returns to, '' when the attempt is unknown.
     */
    public function returnUrl(string $connectId): string
    {
        $attempt = $this->attempt($connectId);

        return null !== $attempt && is_string($attempt['return_url']) ? $attempt['return_url'] : '';
    }

    /**
     * The proof answering the pricing service's check, or null when no attempt with
     * this id is pending.
     */
    public function proof(string $connectId, string $challenge): ?string
    {
        $attempt = $this->attempt($connectId);

        if (null === $attempt || !Protocol::isChallenge($challenge)) {
            return null;
        }

        return Protocol::signVerify($attempt['connect_secret'], $connectId, $challenge);
    }

    /**
     * Keeps an error message for the settings page and returns the id that points
     * to it. The message is stored under a random id, as the browser may not be
     * logged in when the error happens.
     */
    public function rememberError(string $message): string
    {
        $id = $this->settings->randomToken(20);
        $this->save(self::CACHE_ERROR . $id, $message, self::ERROR_TTL);

        return $id;
    }

    /**
     * Returns and forgets a kept error message, '' when there is none.
     */
    public function takeError(string $id): string
    {
        if (1 !== preg_match('/\A[A-Za-z0-9]{20}\z/', $id)) {
            return '';
        }

        $message = $this->load(self::CACHE_ERROR . $id);
        $this->cache->remove(self::CACHE_ERROR . $id);

        return is_string($message) ? $message : '';
    }

    /**
     * Whether the URL is on the pricing service.
     */
    public function isServiceUrl(string $url): bool
    {
        $service = $this->serviceOriginOrEmpty();

        return '' !== $service && $this->urls->origin($url) === $service;
    }

    /**
     * The approval link: the address given by the service, unchanged, followed by
     * a fresh timestamp, the attempt's state and their signature.
     */
    private function consentLink(array $attempt): string
    {
        $ts = (string) time();
        $sig = Protocol::signConsent($attempt['connect_secret'], $attempt['connect_id'], $ts, $attempt['state']);

        return $attempt['consent_url']
            . (str_contains($attempt['consent_url'], '?') ? '&' : '?')
            . 'ts=' . rawurlencode($ts)
            . '&state=' . rawurlencode($attempt['state'])
            . '&sig=' . rawurlencode($sig);
    }

    /**
     * The pending attempt with this id, or null.
     */
    private function attempt(string $connectId): ?array
    {
        if (!Protocol::isConnectId($connectId)) {
            return null;
        }

        $attempt = $this->load($this->attemptKey($connectId));

        if (!is_array($attempt)
            || !isset(
                $attempt['connect_id'],
                $attempt['connect_secret'],
                $attempt['consent_url'],
                $attempt['state'],
                $attempt['user_id'],
                $attempt['service']
            )
            || !hash_equals($attempt['connect_id'], $connectId)
        ) {
            return null;
        }

        return $attempt;
    }

    /**
     * The pending attempt, provided the request carries its state.
     *
     * @throws ConnectException When the attempt is unknown or the state differs.
     */
    private function ownedAttempt(string $connectId, string $state): array
    {
        $attempt = $this->attempt($connectId);

        if (null === $attempt) {
            throw new ConnectException('expired', (string) __('The connection request expired. Please start again.'));
        }

        if (!hash_equals($attempt['state'], $state)) {
            throw new ConnectException(
                'state_mismatch',
                (string) __('This approval does not belong to the connection request started from this store. Please start again.')
            );
        }

        return $attempt;
    }

    /**
     * Deletes an attempt and the pointer of its administrator.
     */
    private function end(array $attempt): void
    {
        $this->cache->remove($this->attemptKey($attempt['connect_id']));

        $userKey = self::CACHE_USER . (int) $attempt['user_id'];
        if ($this->load($userKey) === $attempt['connect_id']) {
            $this->cache->remove($userKey);
        }
    }

    private function attemptKey(string $connectId): string
    {
        return self::CACHE_ATTEMPT . hash('sha256', $connectId);
    }

    /**
     * Exchanges the approval for the connection's signing secret.
     *
     * @return array{signing_secret: string, workspace_id: string, clock_offset: int}
     * @throws ConnectException When the exchange fails.
     */
    private function exchange(array $attempt, string $code, string $webhookUrl): array
    {
        for ($try = 1; $try <= 2; $try++) {
            $timestamp = (string) time();

            [$status, $data] = $this->post(
                $attempt['service'],
                'token',
                '/api/pricing-connect/token',
                [
                    'connect_id' => $attempt['connect_id'],
                    'code' => $code,
                    'webhook_url' => $webhookUrl,
                ],
                [
                    'X-Pricing-Connect-Timestamp' => $timestamp,
                    'X-Pricing-Connect-Signature' => Protocol::signToken(
                        $attempt['connect_secret'],
                        $attempt['connect_id'],
                        $timestamp,
                        $code,
                        $webhookUrl
                    ),
                ]
            );

            if (200 === $status) {
                $secret = $data['signing_secret'] ?? null;
                $workspaceId = $data['workspace_id'] ?? null;

                if (!is_string($secret) || !$this->settings->isValidSecret($secret)
                    || !is_string($workspaceId) || 1 !== preg_match('/\A[\x21-\x7E]{1,128}\z/', $workspaceId)
                ) {
                    throw $this->unexpectedAnswer('token');
                }

                // How far this server's clock is from the service's, so webhook timestamps can be read on the service's clock.
                $serverTime = isset($data['server_time']) && is_int($data['server_time']) ? $data['server_time'] : null;

                return [
                    'signing_secret' => $secret,
                    'workspace_id' => $workspaceId,
                    'clock_offset' => $this->settings->boundClockOffset(null === $serverTime ? 0 : time() - $serverTime),
                ];
            }

            // The only automatic repeat: a conflict the service asks to try once more.
            if (409 === $status && 1 === $try && 'retry' === ($data['error'] ?? null)) {
                continue;
            }

            throw $this->serviceError('token', $status, $data, $attempt['connect_id']);
        }

        throw $this->unexpectedAnswer('token');
    }

    /**
     * Sends JSON to the pricing service, from the server.
     *
     * @return array{0: int, 1: array}
     * @throws ConnectException When the service cannot be reached.
     */
    private function post(string $service, string $call, string $path, array $body, array $headers = []): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::HTTP_TIMEOUT);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::HTTP_TIMEOUT);
        $curl->setHeaders(
            array_merge(
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $headers
            )
        );

        try {
            $curl->post($service . $path, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->log($call . ': ' . $e->getMessage());
            throw new ConnectException(
                'unreachable',
                (string) __('The pricing service could not be reached from this store. Please try again in a moment.')
            );
        }

        $status = (int) $curl->getStatus();
        $raw = (string) $curl->getBody();
        $data = strlen($raw) <= self::MAX_RESPONSE_BYTES ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        if (429 === $status) {
            $responseHeaders = array_change_key_case((array) $curl->getHeaders(), CASE_LOWER);
            $data['error'] = 'rate_limited';
            $data['retry'] = max(1, min(3600, (int) ($responseHeaders['retry-after'] ?? 60)));
        }

        return [$status, $data];
    }

    /**
     * Turns an error answer into a message the administrator can act on, and logs
     * the error code (never a secret) for support.
     */
    private function serviceError(string $call, int $status, array $data, string $connectId = ''): ConnectException
    {
        $code = isset($data['error']) && is_string($data['error'])
            ? substr((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($data['error'])), 0, 40)
            : '';
        $code = '' !== $code ? $code : 'http_' . $status;

        $this->log(sprintf('%s: HTTP %d, %s%s', $call, $status, $code, '' !== $connectId ? ', ' . $connectId : ''));

        $message = match ($code) {
            'clock_out_of_range' => __(
                'Your server clock is more than a day off, so the connection was refused. Ask your host to correct the server clock, or connect manually.'
            ),
            'url_not_on_site', 'webhook_not_on_site' => __(
                'This store\'s addresses were not accepted (%1). Set the secure base URL and base link URL of the default store view to the same HTTPS address, then try again.',
                $code
            ),
            'invalid_signature', 'invalid_timestamp' => __(
                'The connection request did not pass a security check (%1). Please start again; if it keeps happening, connect manually and contact support.',
                $code
            ),
            'invalid_request', 'browser_not_allowed' => __(
                'The connection could not be started (%1). Please update the module, or connect manually.',
                $code
            ),
            'invalid_site_url' => $this->siteNotPublicMessage(),
            'rate_limited', 'too_many_pending' => __(
                'Too many connection attempts. Please try again in %1 seconds.',
                (int) ($data['retry'] ?? 60)
            ),
            'invalid_grant' => __('The approval expired or was already used. Please start again.'),
            'plan_not_included' => __('Your plan does not include automatic price updates.'),
            default => __('The connection could not be completed (%1). Please start again, or connect manually.', $code),
        };

        return new ConnectException($code, (string) $message);
    }

    /**
     * An answer of the right status but the wrong shape.
     */
    private function unexpectedAnswer(string $call): ConnectException
    {
        $this->log($call . ': unexpected answer');

        return new ConnectException(
            'bad_answer',
            (string) __('The pricing service gave an unexpected answer. Please try again.')
        );
    }

    private function siteNotPublicMessage(): string
    {
        return (string) __('Your store must use HTTPS on a public domain to connect.');
    }

    private function save(string $key, mixed $value, int $lifetime): void
    {
        $this->cache->save((string) json_encode($value), $key, [self::CACHE_TAG], $lifetime);
    }

    private function load(string $key): mixed
    {
        $raw = $this->cache->load($key);

        return is_string($raw) && '' !== $raw ? json_decode($raw, true) : null;
    }

    /**
     * Writes to the module log. Never a secret.
     */
    private function log(string $message): void
    {
        $this->logger->warning('connect ' . $message);
    }
}
