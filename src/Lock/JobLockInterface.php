<?php

declare(strict_types=1);

namespace EzPhp\Queue\Lock;

/**
 * Interface JobLockInterface
 *
 * Non-blocking named lock with a time-to-live, shared by `WithoutOverlapping`
 * (execution-time exclusion) and `UniqueQueue` (dispatch-time deduplication).
 *
 * @package EzPhp\Queue\Lock
 */
interface JobLockInterface
{
    /**
     * Try to take the lock without blocking.
     *
     * @param string $key
     * @param int    $ttl Seconds after which the lock expires on its own; 0 = never.
     *
     * @return bool True if the lock was taken, false if it is already held.
     */
    public function acquire(string $key, int $ttl): bool;

    /**
     * Release the lock. Releasing a lock that is not held is a no-op.
     *
     * @param string $key
     *
     * @return void
     */
    public function release(string $key): void;
}
