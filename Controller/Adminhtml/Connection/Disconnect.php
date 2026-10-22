<?php
/**
 * Removes the signing secret.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Controller\Adminhtml\Connection;

use Blackaha\PricingUpdater\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Blackaha_PricingUpdater::manage';

    public function __construct(
        Context $context,
        private readonly Settings $settings
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $this->settings->deleteSecret();

        return $this->resultRedirectFactory->create()->setPath(
            'pricingupdater/connection/index',
            ['_query' => ['pricing_updater_notice' => 'disconnected']]
        );
    }
}
