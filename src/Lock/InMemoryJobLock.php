<?php

declare(strict_types=1);

namespace EzPhp\Queue\Lock;

/**
 * Class InMemoryJobLock
 *
 * Process-local lock, for tests and single-process setups (`queue.driver = memory`).
 * Locks are invisible to other processes — use `CacheJobLock` when web requests and
 * workers run in different processes.
 *
 * @package EzPhp\Queue\Lock
 */
final class InMemoryJobLock implements JobLockInterface
{
    /**
     * Expiry timestamp per held key; 0 means "never expires".
     *
     * @var array<string, int>
     */
    private array $held = [];

    /**
     * @param string $key
     * @param int    $ttl
     *
     * @return bool
     */
    public function acquire(string $key, int $ttl): bool
    {
        $expiresAt = $this->held[$key] ?? null;

        if ($expiresAt !== null && ($expiresAt === 0 || $expiresAt > time())) {
            return false;
        }

        $this->held[$key] = $ttl > 0 ? time() + $ttl : 0;

        return true;
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function release(string $key): void
    {
        unset($this->held[$key]);
    }
}
