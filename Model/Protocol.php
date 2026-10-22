<?php
/**
 * Wire protocol: hashing, signatures, formats and limits.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

/**
 * Protocol helpers.
 *
 * Pure PHP on purpose: nothing in this file depends on Magento. It is the same
 * protocol as the WooCommerce plugin, line for line, so the two cannot drift apart.
 */
class Protocol
{
    public const HEADER_BATCH_ID = 'X-Pricing-Batch-Id';
    public const HEADER_NONCE = 'X-Pricing-Nonce';
    public const HEADER_TIMESTAMP = 'X-Pricing-Timestamp';
    public const HEADER_SIGNATURE = 'X-Pricing-Signature';

    public const TYPE_PRICE_UPDATE = 'price.update';
    public const TYPE_TEST = 'test';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    /** Largest accepted difference, in seconds, between the request timestamp and the store clock. */
    public const MAX_CLOCK_SKEW = 300;

    /** How long, in seconds, a nonce must be remembered at the very least. */
    public const NONCE_TTL = 600;

    /** Seconds a request may take before the store stops and answers 503. */
    public const TIME_BUDGET = 12;

    public const MAX_ITEMS = 500;
    public const MAX_UPDATE_ID_LENGTH = 64;
    public const MAX_REFERENCE_LENGTH = 200;
    public const MAX_MESSAGE_LENGTH = 1000;

    /**
     * Lowercase hex SHA-256 of the exact bytes.
     */
    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * Signature of a request.
     */
    public static function signRequest(
        string $secret,
        string $batchId,
        string $nonce,
        string $timestamp,
        string $body
    ): string {
        return hash_hmac('sha256', implode("\n", ['REQ', $batchId, $nonce, $timestamp, self::sha256($body)]), $secret);
    }

    /**
     * Signature of a response. Batch id and nonce are those of the request being answered.
     */
    public static function signResponse(string $secret, string $batchId, string $nonce, string $body): string
    {
        return hash_hmac('sha256', implode("\n", ['RES', $batchId, $nonce, self::sha256($body)]), $secret);
    }

    /**
     * Signature of the approval link the administrator is sent to.
     */
    public static function signConsent(string $key, string $connectId, string $ts, string $state): string
    {
        return hash_hmac('sha256', implode("\n", ['CONSENT', $connectId, $ts, $state]), $key);
    }

    /**
     * Proof that this site holds the attempt key.
     */
    public static function signVerify(string $key, string $connectId, string $challenge): string
    {
        return hash_hmac('sha256', implode("\n", ['VERIFY', $connectId, $challenge]), $key);
    }

    /**
     * Signature of the request exchanging an approval for the signing secret.
     */
    public static function signToken(
        string $key,
        string $connectId,
        string $timestamp,
        string $code,
        string $webhookUrl
    ): string {
        return hash_hmac('sha256', implode("\n", ['TOKEN', $connectId, $timestamp, $code, $webhookUrl]), $key);
    }

    public static function isConnectId(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\Apcc_[A-Za-z0-9_-]{32}\z/', $value);
    }

    public static function isConnectCode(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[A-Za-z0-9._~-]{16,128}\z/', $value);
    }

    public static function isConnectState(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $value);
    }

    /**
     * Whether the value is a 64-character lowercase hex challenge.
     */
    public static function isChallenge(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $value);
    }

    /**
     * Whether the value is a UUID.
     */
    public static function isBatchId(mixed $value): bool
    {
        return is_string($value)
            && 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value);
    }

    /**
     * Whether the value is 32 lowercase hex characters.
     */
    public static function isNonce(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[0-9a-f]{32}\z/', $value);
    }

    /**
     * Whether the value is a positive integer written in plain digits.
     */
    public static function isTimestamp(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[1-9][0-9]{0,11}\z/', $value);
    }

    /**
     * Whether the value is 64 lowercase hex characters.
     */
    public static function isSignature(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $value);
    }

    /**
     * Whole-valued floats become integers so that 1050000.0 is written as 1050000.
     */
    public static function normalizeNumber(int|float $number): int|float
    {
        if (is_float($number) && floor($number) === $number && abs($number) < 9.0e15) {
            return (int) $number;
        }

        return $number;
    }

    /**
     * Plain decimal notation, no exponent, no thousands separator, no trailing zeros.
     */
    public static function numberToString(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        return rtrim(rtrim(sprintf('%.8F', $number), '0'), '.');
    }

    /**
     * Whether two prices are the same amount.
     */
    public static function samePrice(int|float|string $a, int|float|string $b): bool
    {
        return abs((float) $a - (float) $b) < 0.00005;
    }

    /**
     * Result of an item whose price is now the requested one.
     */
    public static function applied(int|float $price, ?string $reference = null): array
    {
        $result = [
            'status' => self::STATUS_APPLIED,
            'applied_price' => self::normalizeNumber($price),
        ];

        if (null !== $reference && '' !== $reference) {
            $result['reference'] = $reference;
        }

        return $result;
    }

    /**
     * Final negative result of an item.
     */
    public static function failed(string $message, ?string $reference = null): array
    {
        $result = ['status' => self::STATUS_FAILED];

        if (null !== $reference && '' !== $reference) {
            $result['reference'] = $reference;
        }

        $result['message'] = $message;

        return $result;
    }

    /**
     * Encodes the response body. Encode once, sign that string, send that string.
     *
     * @throws \RuntimeException When the body cannot be encoded.
     */
    public static function encodeResponse(string $batchId, string $nonce, array $results): string
    {
        $body = json_encode(
            [
                'batch_id' => $batchId,
                'nonce' => $nonce,
                'results' => array_values($results),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (false === $body) {
            throw new \RuntimeException('The response could not be encoded.');
        }

        return $body;
    }
}
