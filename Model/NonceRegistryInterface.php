<?php
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Model;

/**
 * Remembers the nonces already received.
 */
interface NonceRegistryInterface
{
    /**
     * Records a nonce. Returns false when it was already seen.
     *
     * @throws \BlackAha\DynamicPricing\Exception\HttpException On storage failure.
     */
    public function rememberNonce(string $nonce, int $now): bool;
}
