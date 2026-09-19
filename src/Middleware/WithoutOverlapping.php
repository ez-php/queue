<?php

declare(strict_types=1);

namespace EzPhp\Queue\Middleware;

use EzPhp\Contracts\JobInterface;
use EzPhp\Queue\Job;
use EzPhp\Queue\Lock\JobLockInterface;

/**
 * Class WithoutOverlapping
 *
 * Ensures only one job with the same key runs at a time. When the lock is held by
 * another worker the job is released back onto the queue (without consuming an
 * attempt) and retried after `$releaseAfter` seconds.
 *
 * `$expireAfter` is the safety net that frees the lock if a worker dies mid-job;
 * set it above the job's worst-case runtime.
 *
 * @package EzPhp\Queue\Middleware
 */
final class WithoutOverlapping implements JobMiddlewareInterface
{
    /**
     * WithoutOverlapping Constructor
     *
     * @param JobLockInterface $locks
     * @param string           $key          Lock key; jobs sharing a key exclude each other.
     * @param int              $releaseAfter Seconds before a blocked job is retried.
     * @param int              $expireAfter  Seconds after which the lock frees itself.
     */
    public function __construct(
        private readonly JobLockInterface $locks,
        private readonly string $key,
        private readonly int $releaseAfter = 10,
        private readonly int $expireAfter = 300,
    ) {
    }

    /**
     * @param JobInterface                 $job
     * @param callable(JobInterface): void $next
     *
     * @return void
     */
    public function handle(JobInterface $job, callable $next): void
    {
        $lockKey = 'overlap:' . $this->key;

        if (!$this->locks->acquire($lockKey, $this->expireAfter)) {
            if ($job instanceof Job) {
                $job->releaseAfter($this->releaseAfter);
            }

            return;
        }

        try {
            $next($job);
        } finally {
            $this->locks->release($lockKey);
        }
    }
}
