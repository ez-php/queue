<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;

/**
 * Class Worker
 *
 * Pops jobs from a queue driver and executes them one at a time.
 *
 * On success the job is silently discarded (it was already removed from the
 * queue by pop()). On failure the Worker calls $job->fail() and either
 * re-queues the job (if attempts < maxTries) or records it as permanently
 * failed via $queue->failed().
 *
 * @package EzPhp\Queue
 */
final readonly class Worker
{
    /**
     * Worker Constructor
     *
     * @param QueueInterface $queue
     */
    public function __construct(private QueueInterface $queue)
    {
    }

    /**
     * Run the worker loop indefinitely, polling the given queue.
     *
     * Sleeps $sleep seconds when the queue is empty. Stops after $maxJobs
     * processed jobs when $maxJobs > 0 (useful for testing or one-shot workers).
     *
     * @param string $queue   Queue name to poll.
     * @param int    $sleep   Seconds to sleep when no job is available.
     * @param int    $maxJobs Maximum jobs to process before exiting (0 = unlimited).
     *
     * @return void
     */
    public function work(string $queue = 'default', int $sleep = 3, int $maxJobs = 0): void
    {
        $processed = 0;

        while (true) {
            $ran = $this->runNextJob($queue);

            if (!$ran) {
                if ($maxJobs > 0) {
                    // Bounded mode: no jobs left — exit rather than sleeping forever.
                    break;
                }

                sleep($sleep);
                continue;
            }

            $processed++;

            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }
        }
    }

    /**
     * Pop and process the next available job from the given queue.
     *
     * Returns true if a job was found and processed (successfully or not),
     * false if the queue was empty.
     *
     * @param string $queue
     *
     * @return bool
     */
    public function runNextJob(string $queue = 'default'): bool
    {
        $job = $this->queue->pop($queue);

        if ($job === null) {
            return false;
        }

        $this->process($job);

        return true;
    }

    /**
     * Execute a single job.
     *
     * Increments the attempt counter, calls handle(), and handles any
     * Throwable by either re-queuing (if retries remain) or permanently
     * failing the job.
     *
     * @param JobInterface $job
     *
     * @return void
     */
    protected function process(JobInterface $job): void
    {
        try {
            $job->incrementAttempts();
            $job->handle();
        } catch (\Throwable $e) {
            $job->fail($e);

            if ($job->getAttempts() < $job->getMaxTries()) {
                $this->queue->push($job);
            } else {
                $this->queue->failed($job, $e);
            }
        }
    }
}
