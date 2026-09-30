<?php

declare(strict_types=1);

namespace EzPhp\Queue\Lock;

use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\LockInterface;

/**
 * Class CacheJobLock
 *
 * `JobLockInterface` backed by `ez-php/cache` locks, so the lock is shared between
 * the process that dispatches a job and the worker that runs it (use a shared store
 * such as Redis or Memcached, not the Array driver).
 *
 * Release is ownership-aware where it can be: a lock acquired through this
 * instance is released through the same lock object, so a holder whose TTL ran
 * out can't delete a lock another process has taken since (`WithoutOverlapping`,
 * which acquires and releases in the same worker). A lock this instance did not
 * acquire — `UniqueQueue`'s, taken by the dispatcher and released by the worker —
 * has no owner token here and is force-released; give such locks a TTL longer
 * than the worst-case time a job waits in the queue plus its runtime.
 *
 * The File cache driver's locks are `flock()`s held by the acquiring process: they
 * end when that process exits and can't be released from another one, so they
 * don't carry a unique-job lock from dispatcher to worker. Use Redis or Memcached
 * for `UniqueQueue`.
 *
 * `ez-php/cache` is a soft dependency (`require-dev` + `suggest`): this class is only
 * autoloaded when referenced.
 *
 * @package EzPhp\Queue\Lock
 */
final class CacheJobLock implements JobLockInterface
{
    private const PREFIX = 'queue:lock:';

    /**
     * Locks acquired through this instance and not yet released, by key.
     *
     * @var array<string, LockInterface>
     */
    private array $held = [];

    /**
     * CacheJobLock Constructor
     *
     * @param CacheInterface $cache
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @param string $key
     * @param int    $ttl
     *
     * @return bool
     */
    public function acquire(string $key, int $ttl): bool
    {
        $lock = $this->cache->lock(self::PREFIX . $key, $ttl);

        if (!$lock->acquire()) {
            return false;
        }

        $this->held[$key] = $lock;

        return true;
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function release(string $key): void
    {
        if (isset($this->held[$key])) {
            $this->held[$key]->release();
            unset($this->held[$key]);

            return;
        }

        $this->cache->lock(self::PREFIX . $key)->forceRelease();
    }
}
