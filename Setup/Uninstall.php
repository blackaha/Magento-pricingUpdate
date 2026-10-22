<?php
/**
 * Removes everything the module stored. Product prices are left as they are.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Setup;

use Blackaha\PricingUpdater\Model\Ledger;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        $connection->dropTable($setup->getTable(Ledger::LEDGER_TABLE));
        $connection->dropTable($setup->getTable(Ledger::NONCE_TABLE));
        $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'blackaha_pricing_updater_%']);
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'blackaha_pricing_updater/%']);

        $setup->endSetup();
    }
}
