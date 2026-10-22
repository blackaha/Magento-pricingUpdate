<?php
/**
 * Saves a signing secret entered by hand.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Controller\Adminhtml\Connection;

use Blackaha\PricingUpdater\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class SaveSecret extends Action implements HttpPostActionInterface
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
        $secret = trim((string) $this->getRequest()->getPost('pricing_updater_secret'));
        $query = [];

        if ($this->settings->isValidSecret($secret)) {
            // The manual way keeps the webhook URL the administrator just copied.
            $this->settings->getWebhookSlug();
            $this->settings->saveSecret($secret);
        } else {
            $query = ['pricing_updater_notice' => 'invalid'];
        }

        return $this->resultRedirectFactory->create()->setPath(
            'pricingupdater/connection/index',
            $query ? ['_query' => $query] : []
        );
    }
}
