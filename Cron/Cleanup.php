<?php
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Cron;

use Blackaha\PricingUpdater\Model\Ledger;
use Blackaha\PricingUpdater\Model\Settings;

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
