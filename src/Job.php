<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;
use EzPhp\Queue\Middleware\JobMiddlewareInterface;

/**
 * Class Job
 *
 * Abstract base class for all queue jobs.
 *
 * Subclasses must implement handle() with the actual work. Override fail() to
 * add custom error handling (notifications, logging, etc.). Configure the job
 * via protected properties: $queue, $delay, $maxTries, and $backoff.
 *
 * @package EzPhp\Queue
 */
abstract class Job implements JobInterface
{
    /**
     * The name of the queue this job should be dispatched to.
     *
     * @var string
     */
    protected string $queue = 'default';

    /**
     * Number of seconds to delay the job before it becomes available.
     * Used as the retry delay when $backoff is empty.
     *
     * @var int
     */
    protected int $delay = 0;

    /**
     * Maximum number of times this job may be attempted before it is
     * considered permanently failed.
     *
     * @var int
     */
    protected int $maxTries = 3;

    /**
     * Per-attempt retry delays in seconds.
     *
     * When non-empty the Worker uses these values instead of $delay when
     * re-queuing after a failure. The last element is repeated for any
     * attempt beyond the length of the array.
     *
     * Example: [10, 30, 60] → wait 10 s after attempt 1, 30 s after attempt 2,
     * 60 s for all subsequent attempts.
     *
     * @var list<int>
     */
    protected array $backoff = [];

    /**
     * Number of times this job has been attempted so far.
     *
     * Incremented by the Worker before each execution. Serialised with the
     * job payload so that attempt counts survive across re-queuing cycles.
     *
     * @var int
     */
    private int $attempts = 0;

    /**
     * Delay requested by a middleware via releaseAfter(); null when the job was not released.
     *
     * @var int|null
     */
    private ?int $releaseDelay = null;

    /**
     * True on copies the Worker re-pushes after a retry or release, so `UniqueQueue`
     * does not treat them as fresh dispatches.
     *
     * @var bool
     */
    private bool $requeued = false;

    /**
     * Jobs to push, one after another, once this job succeeded. See JobChain.
     *
     * @var list<Job>
     */
    private array $chain = [];

    /**
     * Called when the job throws an exception during execution.
     *
     * No-op by default — override in subclasses to send notifications or
     * perform cleanup specific to the failure.
     *
     * @param \Throwable $exception
     *
     * @return void
     */
    public function fail(\Throwable $exception): void
    {
    }

    /**
     * Middleware wrapped around handle(), outermost first. Empty by default.
     *
     * Override to add cross-cutting behaviour such as WithoutOverlapping or
     * RateLimited. Called on every execution, so the instances are never
     * serialized with the job.
     *
     * @return list<JobMiddlewareInterface>
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * Ask the Worker to put this job back on the queue instead of counting it as
     * processed. Meant for middleware that decides not to run the job right now;
     * the release does not consume one of the job's attempts.
     *
     * @param int $seconds Delay before the job becomes available again.
     *
     * @return void
     */
    public function releaseAfter(int $seconds = 0): void
    {
        $this->releaseDelay = max(0, $seconds);
    }

    /**
     * Return and clear the delay requested via releaseAfter().
     *
     * @internal Called by Worker after the middleware pipeline; not part of the public job API.
     *
     * @return int|null Null when the job was not released.
     */
    public function pullReleaseDelay(): ?int
    {
        $delay = $this->releaseDelay;
        $this->releaseDelay = null;

        return $delay;
    }

    /**
     * Return a copy that gives the released attempt back and is delayed by $delay.
     *
     * @internal Called by Worker when re-queuing a released job; not part of the public job API.
     *
     * @param int $delay
     *
     * @return static
     */
    public function withRelease(int $delay): static
    {
        $clone = $this->withDelay($delay);
        $clone->attempts = max(0, $clone->attempts - 1);

        return $clone;
    }

    /**
     * Return a copy that runs the given jobs, in order, after it succeeds.
     *
     * @param list<Job> $jobs
     *
     * @return static
     */
    public function withChain(array $jobs): static
    {
        $clone = clone $this;
        $clone->chain = $jobs;

        return $clone;
    }

    /**
     * The job to push after this one succeeded, carrying the rest of the chain.
     *
     * @internal Called by Worker after a successful run; not part of the public job API.
     *
     * @return Job|null Null when no chain is attached or it is exhausted.
     */
    public function nextInChain(): ?Job
    {
        if ($this->chain === []) {
            return null;
        }

        $next = $this->chain[0];

        return $next->withChain([...array_slice($this->chain, 1), ...$next->chain]);
    }

    /**
     * @internal Read by UniqueQueue; not part of the public job API.
     *
     * @return bool
     */
    public function isRequeued(): bool
    {
        return $this->requeued;
    }

    /**
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * @return int
     */
    public function getMaxTries(): int
    {
        return $this->maxTries;
    }

    /**
     * @return int
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * @internal Called by Worker before each execution; not part of the public job API.
     *
     * @return void
     */
    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    /**
     * Return the delay (in seconds) to apply before this job is retried after
     * the given attempt number.
     *
     * When $backoff is non-empty, returns $backoff[$attempt - 1] (clamped to
     * the last element). When $backoff is empty, returns $delay.
     *
     * @internal Called by Worker when re-queuing a failed job; not part of the public job API.
     *
     * @param int $attempt 1-based attempt count (i.e. the value of getAttempts()
     *                     after the failing attempt has been recorded).
     *
     * @return int
     */
    public function getRetryDelay(int $attempt): int
    {
        if ($this->backoff === []) {
            return $this->delay;
        }

        $index = max(0, $attempt - 1);
        $index = min($index, count($this->backoff) - 1);

        return $this->backoff[$index];
    }

    /**
     * Return a clone of this job with the given delay applied.
     *
     * Used by the Worker to apply a backoff delay when re-queuing a failed job
     * without mutating the original instance.
     *
     * @internal Called by Worker for retry backoff; not part of the public job API.
     *
     * @param int $delay Seconds to delay.
     *
     * @return static
     */
    public function withDelay(int $delay): static
    {
        $clone = clone $this;
        $clone->delay = $delay;
        $clone->requeued = true;

        return $clone;
    }
}
