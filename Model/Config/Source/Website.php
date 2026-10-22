<?php
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Websites, plus "not chosen".
 */
class Website implements OptionSourceInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => 0, 'label' => __('-- Not chosen --')]];

        foreach ($this->storeManager->getWebsites() as $website) {
            $options[] = ['value' => (int) $website->getId(), 'label' => $website->getName()];
        }

        return $options;
    }
}
