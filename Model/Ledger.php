<?php
/**
 * Persistence: result ledger, nonce registry and per-update locks.
 */
declare(strict_types=1);

namespace Blackaha\PricingUpdater\Model;

use Blackaha\PricingUpdater\Exception\HttpException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Database access for the module's own tables.
 *
 * A database failure is never turned into a result: it is thrown, so that the
 * request is answered with a server error instead.
 */
class Ledger implements NonceRegistryInterface
{
    public const LEDGER_TABLE = 'blackaha_pricing_updater_ledger';
    public const NONCE_TABLE = 'blackaha_pricing_updater_nonce';

    /** Seconds a nonce is kept, comfortably above the required minimum. */
    public const NONCE_RETENTION = 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $locks
    ) {
    }

    /**
     * The recorded result of an update, or null when it was never concluded.
     *
     * @throws HttpException On database failure.
     */
    public function getResult(string $updateId): ?array
    {
        try {
            $connection = $this->connection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from(
                        $this->resource->getTableName(self::LEDGER_TABLE),
                        ['update_id', 'status', 'applied_price', 'reference', 'message']
                    )
                    ->where('update_id = ?', $updateId)
            );
        } catch (\Throwable $e) {
            throw new HttpException(503, 'storage_unavailable', 'The result ledger could not be read.');
        }

        if (!$row) {
            return null;
        }

        $result = Protocol::STATUS_APPLIED === $row['status']
            ? Protocol::applied((float) $row['applied_price'], $row['reference'])
            : Protocol::failed((string) $row['message'], $row['reference']);

        return array_merge(['update_id' => $row['update_id']], $result);
    }

    /**
     * Records the final result of an update.
     *
     * @param array $result Result, as built by Protocol::applied() or Protocol::failed().
     * @throws HttpException On database failure.
     */
    public function saveResult(string $updateId, string $batchId, string $sku, array $result): void
    {
        $applied = Protocol::STATUS_APPLIED === $result['status'];

        try {
            $this->connection()->insert(
                $this->resource->getTableName(self::LEDGER_TABLE),
                [
                    'update_id' => $updateId,
                    'batch_id' => $batchId,
                    'sku' => mb_substr($sku, 0, 191),
                    'status' => $result['status'],
                    'applied_price' => $applied ? Protocol::numberToString($result['applied_price']) : null,
                    'reference' => $result['reference'] ?? null,
                    'message' => $result['message'] ?? null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            throw new HttpException(500, 'storage_unavailable', 'The result could not be recorded.');
        }
    }

    /**
     * @inheritdoc
     *
     * The primary key makes this atomic: of two identical requests, only one inserts.
     */
    public function rememberNonce(string $nonce, int $now): bool
    {
        try {
            $inserted = $this->connection()->insertArray(
                $this->resource->getTableName(self::NONCE_TABLE),
                ['nonce', 'seen_at'],
                [[$nonce, $now]],
                AdapterInterface::INSERT_IGNORE
            );
        } catch (\Throwable $e) {
            throw new HttpException(503, 'storage_unavailable', 'The nonce could not be recorded.');
        }

        return 1 === (int) $inserted;
    }

    /**
     * Takes the lock of an update without waiting. Returns false when another
     * request holds it.
     */
    public function acquireLock(string $updateId): bool
    {
        try {
            return $this->locks->lock($this->lockName($updateId), 0);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function releaseLock(string $updateId): void
    {
        try {
            $this->locks->unlock($this->lockName($updateId));
        } catch (\Throwable $e) {
            // The lock ends with the connection anyway.
        }
    }

    /**
     * Lock name: unique to the update; the lock manager adds the installation prefix.
     */
    private function lockName(string $updateId): string
    {
        return 'blackaha_pu_' . substr(hash('sha256', $updateId), 0, 40);
    }

    /**
     * The most recent recorded results, newest first.
     *
     * @return array[]
     */
    public function recent(int $limit): array
    {
        $connection = $this->connection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName(self::LEDGER_TABLE),
                    ['update_id', 'sku', 'status', 'applied_price', 'reference', 'message', 'created_at']
                )
                ->order('created_at DESC')
                ->limit($limit)
        );
    }

    /**
     * Deletes results and nonces that are no longer needed.
     *
     * @param int $days Days recorded results are kept, at least 1.
     */
    public function cleanup(int $days): void
    {
        $connection = $this->connection();

        $connection->delete(
            $this->resource->getTableName(self::LEDGER_TABLE),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)]
        );

        $connection->delete(
            $this->resource->getTableName(self::NONCE_TABLE),
            ['seen_at < ?' => time() - self::NONCE_RETENTION]
        );
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
