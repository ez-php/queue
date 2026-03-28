<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\QueueInterface;

/**
 * Interface FailedJobRepositoryInterface
 *
 * Provides read/retry/delete access to the permanent failed-job store.
 * Implemented by queue drivers that support a durable failure record.
 *
 * @package EzPhp\Queue
 */
interface FailedJobRepositoryInterface
{
    /**
     * Return all failed jobs in insertion order.
     *
     * @return list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array;

    /**
     * Move a failed job back onto its original queue.
     *
     * Returns false if no failed job with the given id exists.
     *
     * @param int            $id    Failed-job record id.
     * @param QueueInterface $queue Queue driver to push the retried job onto.
     *
     * @return bool
     */
    public function retry(int $id, QueueInterface $queue): bool;

    /**
     * Permanently delete a single failed-job record.
     *
     * Returns false if no failed job with the given id exists.
     *
     * @param int $id Failed-job record id.
     *
     * @return bool
     */
    public function forget(int $id): bool;

    /**
     * Delete every failed-job record.
     *
     * @return void
     */
    public function flush(): void;
}
