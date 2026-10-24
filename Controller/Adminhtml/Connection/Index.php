<?php
/**
 * Catalog > Pricing Updater.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * One card to connect and show the state of the connection.
 *
 * A public action as far as the URL secret key goes (the ACL still applies): the
 * browser comes back here from the pricing service, on a URL built before it left.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'BlackAha_DynamicPricing::manage';

    /**
     * @var string[]
     */
    protected $_publicActions = ['index'];

    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('BlackAha_DynamicPricing::connection');
        $page->getConfig()->getTitle()->prepend(__('Pricing Updater'));

        return $page;
    }
}
