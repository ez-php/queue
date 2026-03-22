<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;

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
     * @param int $delay Seconds to delay.
     *
     * @return static
     */
    public function withDelay(int $delay): static
    {
        $clone = clone $this;
        $clone->delay = $delay;

        return $clone;
    }
}
