<?php

declare(strict_types=1);

namespace EzPhp\Queue\Middleware;

use EzPhp\Contracts\JobInterface;

/**
 * Interface JobMiddlewareInterface
 *
 * Wraps the execution of a job (`handle()`), like HTTP middleware wraps a request.
 * A middleware either calls `$next($job)` to continue the chain, or returns without
 * calling it to skip the job — typically after `Job::releaseAfter()` so the Worker
 * puts the job back on the queue instead of counting it as processed.
 *
 * Exceptions thrown by a middleware are treated exactly like exceptions thrown by
 * `handle()`: the Worker retries or permanently fails the job.
 *
 * @package EzPhp\Queue\Middleware
 */
interface JobMiddlewareInterface
{
    /**
     * @param JobInterface                $job
     * @param callable(JobInterface): void $next Continues with the next middleware, or `handle()`.
     *
     * @return void
     */
    public function handle(JobInterface $job, callable $next): void;
}
