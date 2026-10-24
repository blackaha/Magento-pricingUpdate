<?php
/**
 * GET <store>/dynamicpricing/connect/verify?connect_id=&challenge=
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Controller\Connect;

use BlackAha\DynamicPricing\Model\Connector;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Called by the pricing service, not the browser, to make sure this site is the
 * one that asked to connect. Answers only while an attempt with the same id is
 * pending; otherwise it answers as if it did not exist.
 */
class Verify implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly Connector $connector
    ) {
    }

    public function execute(): Raw
    {
        ob_start();
        $level = ob_get_level();

        try {
            $proof = $this->connector->proof(
                (string) $this->request->getParam('connect_id'),
                (string) $this->request->getParam('challenge')
            );
        } catch (\Throwable $e) {
            $proof = null;
        } finally {
            while (ob_get_level() >= $level) {
                ob_end_clean();
            }
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);

        if (null === $proof) {
            $result->setHttpResponseCode(404);
            $result->setContents('{"code":"not_found","message":"No route was found matching the URL and request method."}');

            return $result;
        }

        $result->setHttpResponseCode(200);
        $result->setContents((string) json_encode(['proof' => $proof]));

        return $result;
    }
}
