<?php
/**
 * Starts the one-click connection.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Controller\Adminhtml\Connection;

use BlackAha\DynamicPricing\Exception\ConnectException;
use BlackAha\DynamicPricing\Model\Connector;
use BlackAha\DynamicPricing\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Opens an attempt and sends the browser to the pricing service to approve it.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'BlackAha_DynamicPricing::manage';

    public function __construct(
        Context $context,
        private readonly Connector $connector,
        private readonly Settings $settings
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $returnUrl = $this->getUrl('dynamicpricing/connection/index', ['_nosecret' => true]);
        $this->settings->saveAdminPageUrl($returnUrl);

        try {
            $url = $this->connector->start((int) $this->_auth->getUser()->getId(), $returnUrl);
        } catch (ConnectException $e) {
            return $redirect->setPath(
                'dynamicpricing/connection/index',
                ['_query' => [
                    'dynamic_pricing_notice' => 'connect_failed',
                    'dynamic_pricing_error' => $this->connector->rememberError($e->getMessage()),
                ]]
            );
        }

        // The pricing service is the only external address this action sends the browser to.
        if (!$this->connector->isServiceUrl($url)) {
            return $redirect->setPath('dynamicpricing/connection/index');
        }

        return $redirect->setUrl($url);
    }
}
