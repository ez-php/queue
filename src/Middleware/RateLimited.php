<?php

declare(strict_types=1);

namespace EzPhp\Queue\Middleware;

use EzPhp\Contracts\JobInterface;
use EzPhp\Queue\Job;
use EzPhp\RateLimiter\RateLimiterInterface;

/**
 * Class RateLimited
 *
 * Lets at most `$maxAttempts` jobs with the same key run per `$decaySeconds`
 * window. Jobs over the limit are released back onto the queue (without consuming
 * an attempt) and retried once the window has room again.
 *
 * `ez-php/rate-limiter` is a soft dependency (`require-dev` + `suggest`): this class
 * is only autoloaded when referenced.
 *
 * @package EzPhp\Queue\Middleware
 */
final class RateLimited implements JobMiddlewareInterface
{
    /**
     * RateLimited Constructor
     *
     * @param RateLimiterInterface $limiter
     * @param string               $key          Limiter key; jobs sharing a key share the budget.
     * @param int                  $maxAttempts  Jobs allowed per window.
     * @param int                  $decaySeconds Window length in seconds.
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
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
        $limiterKey = 'queue:' . $this->key;

        if (!$this->limiter->attempt($limiterKey, $this->maxAttempts, $this->decaySeconds)) {
            if ($job instanceof Job) {
                $job->releaseAfter(max(1, $this->limiter->availableIn($limiterKey)));
            }

            return;
        }

        $next($job);
    }
}
