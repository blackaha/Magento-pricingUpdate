<?php
/**
 * Applies a verified batch.
 */
declare(strict_types=1);

namespace BlackAha\DynamicPricing\Model;

use BlackAha\DynamicPricing\Exception\HttpException;
use Magento\Catalog\Model\Product;

/**
 * For each item: recorded result, else lock, resolve, write, record.
 */
class Processor
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolver,
        private readonly Writer $writer,
        private readonly PriceScope $priceScope
    ) {
    }

    /**
     * Processes every item of a batch and returns one result per item, in order.
     *
     * An update id that was already concluded is answered from the ledger and never
     * written twice. There is no partial answer: anything that prevents a complete
     * one is thrown.
     *
     * @param array $request Verified request.
     * @return array[]
     * @throws HttpException When the batch cannot be completed now.
     */
    public function process(array $request): array
    {
        $deadline = microtime(true) + Protocol::TIME_BUDGET;
        $scope = $this->priceScope->resolve();
        $results = [];

        foreach ($request['items'] as $item) {
            $updateId = $item['update_id'];

            // Out of time: what is already recorded stays recorded; the rest is left for a later request.
            if (microtime(true) >= $deadline) {
                throw new HttpException(503, 'out_of_time', 'The batch could not be completed in time.');
            }

            $result = $this->ledger->getResult($updateId);
            if (null !== $result) {
                $results[] = $result;
                continue;
            }

            if (!$this->ledger->acquireLock($updateId)) {
                throw new HttpException(503, 'update_in_progress', 'This update is being processed by another request.');
            }

            try {
                // Again, now that no other request can conclude it.
                $result = $this->ledger->getResult($updateId);

                if (null === $result) {
                    $result = array_merge(['update_id' => $updateId], $this->limit($this->apply($item, $scope)));
                    $this->ledger->saveResult($updateId, $request['batch_id'], (string) $item['sku'], $result);
                }

                $results[] = $result;
            } finally {
                $this->ledger->releaseLock($updateId);
            }
        }

        return $results;
    }

    /**
     * Applies one item.
     *
     * @return array Applied or failed result.
     */
    private function apply(array $item, array $scope): array
    {
        if (null === $item['sku'] || '' === trim($item['sku'])) {
            return Protocol::failed(
                (string) __('The update did not include a SKU, so no product could be matched.')
            );
        }

        $product = $this->resolver->resolve($item['sku'], $scope['read_store_id']);

        if (is_array($product)) {
            return $product;
        }

        if (!$product instanceof Product) {
            return Protocol::failed(
                (string) __('The product with SKU "%1" could not be loaded.', $item['sku'])
            );
        }

        return $this->writer->apply($product, $item, $scope);
    }

    /**
     * Keeps the free-text fields within the lengths the protocol allows.
     */
    private function limit(array $result): array
    {
        if (isset($result['reference'])) {
            $result['reference'] = mb_substr($result['reference'], 0, Protocol::MAX_REFERENCE_LENGTH);
        }

        if (isset($result['message'])) {
            $result['message'] = mb_substr($result['message'], 0, Protocol::MAX_MESSAGE_LENGTH);
        }

        return $result;
    }
}
