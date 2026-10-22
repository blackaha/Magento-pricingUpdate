<?php
/**
 * A connection attempt that cannot go on, with the message to show.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Exception;

/**
 * Carries a short code for the log and a message the administrator can act on.
 */
class ConnectException extends \RuntimeException
{
    /**
     * @param string $reason Short code, for the log.
     * @param string $message Message for the administrator, already translated.
     */
    public function __construct(
        private readonly string $reason,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
