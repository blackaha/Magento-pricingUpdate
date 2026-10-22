<?php
/**
 * An outcome that must be answered with a plain HTTP error instead of a signed result.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Exception;

/**
 * Carries the HTTP status and a machine-readable code.
 *
 * Pure PHP on purpose: the protocol tests run without Magento.
 */
class HttpException extends \Exception
{
    /**
     * @param int $status HTTP status to answer with.
     * @param string $errorCode Machine-readable code.
     * @param string $message Short explanation.
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
