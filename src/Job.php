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
 * via protected properties: $queue, $delay, and $maxTries.
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
}
