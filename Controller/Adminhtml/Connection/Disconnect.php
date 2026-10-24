<?php
/**
 * Removes the signing secret.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Controller\Adminhtml\Connection;

use BlackAha\DynamicPricing\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'BlackAha_DynamicPricing::manage';

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
            'dynamicpricing/connection/index',
            ['_query' => ['dynamic_pricing_notice' => 'disconnected']]
        );
    }
}
