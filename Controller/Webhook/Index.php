<?php
/**
 * The webhook endpoint: POST <store>/pricingupdater/webhook/index/slug/<slug>
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Controller\Webhook;

use Blackaha\PricingUpdater\Exception\HttpException;
use Blackaha\PricingUpdater\Model\Processor;
use Blackaha\PricingUpdater\Model\Protocol;
use Blackaha\PricingUpdater\Model\Settings;
use Blackaha\PricingUpdater\Model\Verifier;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/**
 * Receives the raw request and sends the signed answer byte for byte.
 *
 * Not a Web API route on purpose: the framework would encode the response again
 * and the signature would no longer match the bytes sent. Authentication is the
 * HMAC signature, so the form key check is switched off. GET is routed here too,
 * so that anything but POST is answered with 405 rather than "not found".
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Verifier $verifier,
        private readonly Processor $processor,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        // A different slug is a URL that does not exist for this store.
        $stored = $this->settings->getStoredSlug();
        if ('' === $stored || !hash_equals($stored, (string) $this->request->getParam('slug'))) {
            return $this->error(404, 'not_found', 'No route was found matching the URL and request method.');
        }

        // Anything printed while working (notices, output of other modules) would corrupt the signed body.
        ob_start();
        $level = ob_get_level();

        try {
            return $this->answer();
        } catch (HttpException $e) {
            return $this->error($e->getStatus(), $e->getErrorCode(), $e->getMessage());
        } catch (\Throwable $e) {
            // Unknown outcome: nothing is recorded, and the answer is a plain server error.
            $this->logger->error('webhook: ' . $e->getMessage());

            return $this->error(500, 'internal_error', 'The request could not be processed.');
        } finally {
            while (ob_get_level() >= $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Verifies, processes and signs the request.
     *
     * @throws HttpException When the request must be answered with an HTTP error.
     */
    private function answer(): Raw
    {
        $secret = $this->settings->getSecret();

        $verified = $this->verifier->verify(
            (string) $this->request->getMethod(),
            [
                'batch_id' => $this->header(Protocol::HEADER_BATCH_ID),
                'nonce' => $this->header(Protocol::HEADER_NONCE),
                'timestamp' => $this->header(Protocol::HEADER_TIMESTAMP),
                'signature' => $this->header(Protocol::HEADER_SIGNATURE),
            ],
            (string) $this->request->getContent(),
            $secret,
            $this->settings->getConnection()['workspace_id'],
            $this->settings->serviceTime()
        );

        // A test request is answered with an empty, signed result and changes nothing in the store.
        $results = Protocol::TYPE_TEST === $verified['type'] ? [] : $this->processor->process($verified);

        $this->settings->recordRequest($verified['workspace_id'], $verified['type'], time());

        // Encoded exactly once: these are the bytes that get signed and the bytes that get sent.
        $body = Protocol::encodeResponse($verified['batch_id'], $verified['nonce'], $results);
        $signature = Protocol::signResponse($secret, $verified['batch_id'], $verified['nonce'], $body);

        $result = $this->raw(200);
        $result->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $result->setHeader(Protocol::HEADER_SIGNATURE, $signature, true);
        $result->setContents($body);

        return $result;
    }

    /**
     * A plain, unsigned HTTP error.
     */
    private function error(int $status, string $code, string $message): Raw
    {
        $result = $this->raw($status);
        $result->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $result->setContents((string) json_encode(['code' => $code, 'message' => $message]));

        return $result;
    }

    /**
     * An answer that no cache may keep, errors included.
     */
    private function raw(int $status): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        return $result;
    }

    private function header(string $name): ?string
    {
        $value = $this->request->getHeader($name);

        return is_string($value) ? $value : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
