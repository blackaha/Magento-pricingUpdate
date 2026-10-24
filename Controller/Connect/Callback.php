<?php
/**
 * GET <store>/dynamicpricing/connect/callback?connect_id=&state=&code=|error=access_denied
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Controller\Connect;

use BlackAha\DynamicPricing\Exception\ConnectException;
use BlackAha\DynamicPricing\Model\Connector;
use BlackAha\DynamicPricing\Model\Protocol;
use BlackAha\DynamicPricing\Model\Settings;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Escaper;
use Psr\Log\LoggerInterface;

/**
 * Where the browser lands after the administrator answered on the pricing service.
 *
 * No admin login is required here: the browser arrives from another site, which
 * may keep the admin session cookie from being sent. The attempt's state, random,
 * single use and only known to the browser that started the attempt, is the
 * proof. The approval is exchanged right away, server to server, and the browser
 * is then sent on to the admin page by a small page on this site, so that the
 * next request starts here and carries the admin cookies.
 */
class Callback implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly Connector $connector,
        private readonly Settings $settings,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        $connectId = (string) $this->request->getParam('connect_id');
        $state = (string) $this->request->getParam('state');

        if (!Protocol::isConnectId($connectId) || !Protocol::isConnectState($state)) {
            $result = $this->rawFactory->create();
            $result->setHttpResponseCode(404);
            $result->setHeader('Cache-Control', 'no-store', true);
            $result->setContents('');

            return $result;
        }

        $returnUrl = $this->connector->returnUrl($connectId) ?: $this->settings->getAdminPageUrl();

        try {
            if ('access_denied' === $this->request->getParam('error')) {
                $this->connector->decline($connectId, $state);
                $notice = ['dynamic_pricing_notice' => 'connect_cancelled'];
            } else {
                $this->connector->complete($connectId, $state, (string) $this->request->getParam('code'));
                $notice = [];
            }
        } catch (ConnectException $e) {
            $notice = [
                'dynamic_pricing_notice' => 'connect_failed',
                'dynamic_pricing_error' => $this->connector->rememberError($e->getMessage()),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('connect callback: ' . $e->getMessage());
            $notice = [
                'dynamic_pricing_notice' => 'connect_failed',
                'dynamic_pricing_error' => $this->connector->rememberError(
                    (string) __('The connection could not be completed. Please start again, or connect manually.')
                ),
            ];
        }

        return $this->bounce($returnUrl, $notice);
    }

    /**
     * The small page that takes the browser to the admin page.
     */
    private function bounce(string $url, array $notice): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('Referrer-Policy', 'no-referrer', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);

        if ('' === $url) {
            $result->setContents(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'
                . $this->escaper->escapeHtml(__('Pricing Updater'))
                . '</title></head><body><p>'
                . $this->escaper->escapeHtml(__('Open Catalog > Pricing Updater in the Magento admin to see the result.'))
                . '</p></body></html>'
            );

            return $result;
        }

        if ($notice) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($notice);
        }

        $result->setContents(
            sprintf(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
                . '<meta http-equiv="refresh" content="0;url=%1$s"><title>%2$s</title></head>'
                . '<body><p><a href="%1$s">%2$s</a></p><script>window.location.replace(%3$s);</script></body></html>',
                $this->escaper->escapeUrl($url),
                $this->escaper->escapeHtml(__('Continue')),
                (string) json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            )
        );

        return $result;
    }
}
