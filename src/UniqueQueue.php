<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Lock\JobLockInterface;
use Throwable;

/**
 * Class UniqueQueue
 *
 * `QueueInterface` decorator that silently drops a pushed `ShouldBeUnique` job when
 * one with the same `uniqueId()` is already queued or running. All other jobs, and
 * every other operation, pass straight through to the inner queue.
 *
 * The lock is released by the `Worker` (give it the same `JobLockInterface`) once
 * the job succeeded or permanently failed; it is deliberately kept across retries.
 *
 * Opt-in: wrap your `QueueInterface` binding yourself. The wrapper does not
 * implement `FailedJobRepositoryInterface`, so `queue:failed` needs the inner queue.
 *
 * @package EzPhp\Queue
 */
final class UniqueQueue implements QueueInterface
{
    /**
     * Prefix shared with the Worker's release, see `lockKey()`.
     */
    private const KEY_PREFIX = 'unique:';

    /**
     * UniqueQueue Constructor
     *
     * @param QueueInterface   $inner
     * @param JobLockInterface $locks
     */
    public function __construct(
        private readonly QueueInterface $inner,
        private readonly JobLockInterface $locks,
    ) {
    }

    /**
     * Lock key for a unique job. Used by both the queue (acquire) and the Worker (release).
     *
     * @param ShouldBeUnique $job
     *
     * @return string
     */
    public static function lockKey(ShouldBeUnique $job): string
    {
        return self::KEY_PREFIX . $job->uniqueId();
    }

    /**
     * @param JobInterface $job
     *
     * @return void
     */
    public function push(JobInterface $job): void
    {
        // Retries and releases are re-pushed by the Worker while the lock is still held
        // by the original dispatch, so only fresh dispatches compete for it.
        if ($job instanceof ShouldBeUnique && $this->isFreshDispatch($job)
            && !$this->locks->acquire(self::lockKey($job), $job->uniqueFor())) {
            return;
        }

        $this->inner->push($job);
    }

    /**
     * @param string $queue
     *
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        return $this->inner->pop($queue);
    }

    /**
     * @param string $queue
     *
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        return $this->inner->size($queue);
    }

    /**
     * @param JobInterface $job
     * @param Throwable    $exception
     *
     * @return void
     */
    public function failed(JobInterface $job, Throwable $exception): void
    {
        $this->inner->failed($job, $exception);
    }

    /**
     * @param JobInterface $job
     *
     * @return bool
     */
    private function isFreshDispatch(JobInterface $job): bool
    {
        return $job->getAttempts() === 0 && !($job instanceof Job && $job->isRequeued());
    }
}
