<?php
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Cron;

use BlackAha\DynamicPricing\Model\Ledger;
use BlackAha\DynamicPricing\Model\Settings;

/**
 * Deletes results and nonces that are no longer needed. Runs daily.
 */
class Cleanup
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings
    ) {
    }

    public function execute(): void
    {
        $this->ledger->cleanup($this->settings->getRetentionDays());
    }
}
