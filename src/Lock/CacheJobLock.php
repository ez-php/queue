<?php

declare(strict_types=1);

namespace EzPhp\Queue\Lock;

use EzPhp\Cache\CacheInterface;

/**
 * Class CacheJobLock
 *
 * `JobLockInterface` backed by `ez-php/cache` locks, so the lock is shared between
 * the process that dispatches a job and the worker that runs it (use a shared store
 * such as Redis or the File driver, not the Array driver).
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
        return $this->cache->lock(self::PREFIX . $key, $ttl)->acquire();
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function release(string $key): void
    {
        $this->cache->lock(self::PREFIX . $key)->release();
    }
}
