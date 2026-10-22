<?php
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

/**
 * Remembers the nonces already received.
 */
interface NonceRegistryInterface
{
    /**
     * Records a nonce. Returns false when it was already seen.
     *
     * @throws \Blackaha\PricingUpdater\Exception\HttpException On storage failure.
     */
    public function rememberNonce(string $nonce, int $now): bool;
}
