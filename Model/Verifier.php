<?php
/**
 * Request verification.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Blackaha\PricingUpdater\Exception\HttpException;

/**
 * Runs the request checks in their mandatory order and stops at the first failure.
 *
 * Pure PHP apart from the nonce registry, so that it runs in the protocol tests.
 */
class Verifier
{
    public function __construct(
        private readonly NonceRegistryInterface $nonces
    ) {
    }

    /**
     * Verifies a request and returns its validated content.
     *
     * The order matters. The timestamp is checked before the signature so that stale
     * requests cost nothing, and a clock problem is answered with 400, not 401, as it
     * is not an authentication failure. The nonce is recorded last, only once the
     * request is known to be genuine.
     *
     * @param string $method HTTP method.
     * @param array $headers Keys batch_id, nonce, timestamp, signature; null when absent.
     * @param string $body Raw request body.
     * @param string $secret Stored signing secret, '' when none.
     * @param string $workspaceId Workspace this store is bound to, '' when not bound yet.
     * @param int $now Current Unix time on the sender's clock.
     * @return array{type: string, batch_id: string, nonce: string, timestamp: int, workspace_id: string, items: array}
     * @throws HttpException When a check fails.
     */
    public function verify(
        string $method,
        array $headers,
        string $body,
        string $secret,
        string $workspaceId,
        int $now
    ): array {
        // 1. Method.
        if ('POST' !== $method) {
            throw new HttpException(405, 'method_not_allowed', 'Only POST is accepted.');
        }

        // 2. A secret is configured.
        if ('' === $secret) {
            throw new HttpException(503, 'not_configured', 'The store has no signing secret yet.');
        }

        // 3. The four headers, well-formed.
        $batchId = $headers['batch_id'] ?? null;
        $nonce = $headers['nonce'] ?? null;
        $timestamp = $headers['timestamp'] ?? null;
        $signature = $headers['signature'] ?? null;

        if (!Protocol::isBatchId($batchId)
            || !Protocol::isNonce($nonce)
            || !Protocol::isTimestamp($timestamp)
            || !Protocol::isSignature($signature)
        ) {
            throw new HttpException(400, 'invalid_headers', 'A required header is missing or malformed.');
        }

        // 4. Freshness.
        if (abs($now - (int) $timestamp) > Protocol::MAX_CLOCK_SKEW) {
            throw new HttpException(
                400,
                'timestamp_out_of_range',
                'The request timestamp is too far from the store clock.'
            );
        }

        // 5. Signature, over the raw body bytes.
        $expected = Protocol::signRequest($secret, $batchId, $nonce, $timestamp, $body);
        if (!hash_equals($expected, $signature)) {
            throw new HttpException(401, 'invalid_signature', 'The request signature does not match.');
        }

        // 6. Body shape, and headers equal to body.
        $payload = $this->parseBody($body, $batchId, $nonce, (int) $timestamp);

        // 7. The secret is used by the workspace it belongs to.
        if ('' !== $workspaceId && !hash_equals($workspaceId, $payload['workspace_id'])) {
            throw new HttpException(401, 'workspace_mismatch', 'This store is connected to a different workspace.');
        }

        // 8. Replay protection.
        // Recorded on this server's own clock, which is the one the cleanup uses.
        if (!$this->nonces->rememberNonce($nonce, time())) {
            throw new HttpException(400, 'nonce_reused', 'This request was already received.');
        }

        return $payload;
    }

    /**
     * Validates the body. Unknown fields are ignored; missing or mistyped ones are rejected.
     *
     * @throws HttpException When the body is not acceptable.
     */
    private function parseBody(string $body, string $batchId, string $nonce, int $timestamp): array
    {
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw $this->invalidBody('The body is not a JSON object.');
        }

        $type = $data['type'] ?? null;
        if (Protocol::TYPE_PRICE_UPDATE !== $type && Protocol::TYPE_TEST !== $type) {
            throw $this->invalidBody('Unknown type.');
        }

        if (!isset($data['batch_id'], $data['nonce'], $data['timestamp'])
            || $data['batch_id'] !== $batchId
            || $data['nonce'] !== $nonce
            || $data['timestamp'] !== $timestamp
        ) {
            throw $this->invalidBody('batch_id, nonce and timestamp must equal the headers.');
        }

        if (!isset($data['workspace_id']) || !is_string($data['workspace_id']) || '' === $data['workspace_id']) {
            throw $this->invalidBody('workspace_id is required.');
        }

        if (!isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items'])) {
            throw $this->invalidBody('items must be an array.');
        }

        if (count($data['items']) > Protocol::MAX_ITEMS) {
            throw $this->invalidBody('Too many items.');
        }

        // A test never carries items and never writes anything.
        if (Protocol::TYPE_TEST === $type && [] !== $data['items']) {
            throw $this->invalidBody('A test must not contain items.');
        }

        $items = [];
        foreach ($data['items'] as $rawItem) {
            $item = $this->parseItem($rawItem);

            if (isset($items[$item['update_id']])) {
                throw $this->invalidBody('Duplicate update_id.');
            }

            $items[$item['update_id']] = $item;
        }

        return [
            'type' => $type,
            'batch_id' => $batchId,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
            'workspace_id' => $data['workspace_id'],
            'items' => array_values($items),
        ];
    }

    /**
     * Validates one item.
     *
     * @return array{update_id: string, sku: ?string, target_price: int|float, currency: ?string}
     * @throws HttpException When the item is not acceptable.
     */
    private function parseItem(mixed $item): array
    {
        if (!is_array($item)) {
            throw $this->invalidBody('Each item must be an object.');
        }

        $updateId = $item['update_id'] ?? null;
        if (!is_string($updateId)
            || '' === $updateId
            || mb_strlen($updateId, 'UTF-8') > Protocol::MAX_UPDATE_ID_LENGTH
        ) {
            throw $this->invalidBody('update_id must be a string of 1 to 64 characters.');
        }

        if (!isset($item['product']) || !is_array($item['product'])) {
            throw $this->invalidBody('product is required.');
        }

        $sku = $item['product']['sku'] ?? null;
        if (null !== $sku && !is_string($sku)) {
            throw $this->invalidBody('product.sku must be a string.');
        }

        $targetPrice = $item['target_price'] ?? null;
        if (!$this->isNumber($targetPrice)) {
            throw $this->invalidBody('target_price must be a number.');
        }

        if (isset($item['previous_price']) && !$this->isNumber($item['previous_price'])) {
            throw $this->invalidBody('previous_price must be a number or null.');
        }

        $currency = $item['currency'] ?? null;
        if (null !== $currency && !is_string($currency)) {
            throw $this->invalidBody('currency must be a string or null.');
        }

        return [
            'update_id' => $updateId,
            'sku' => $sku,
            'target_price' => $targetPrice,
            'currency' => $currency,
        ];
    }

    /**
     * Whether the value is a finite JSON number.
     */
    private function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private function invalidBody(string $message): HttpException
    {
        return new HttpException(400, 'invalid_body', $message);
    }
}
