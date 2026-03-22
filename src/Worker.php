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
 * **Priority queues:** pass an ordered list of queue names to work() /
 * runNextJob(). The Worker tries each queue in order and processes the first
 * job it finds, giving earlier queues higher priority.
 *
 * **Retry backoff:** if the job's $backoff property is set, the Worker applies
 * the appropriate delay when re-queuing by calling Job::withDelay().
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
     * Run the worker loop indefinitely, polling the given queue(s).
     *
     * Sleeps $sleep seconds when all queues are empty. Stops after $maxJobs
     * processed jobs when $maxJobs > 0 (useful for testing or one-shot workers).
     *
     * When $queues is an array, queues are polled in the given order on each
     * loop iteration — the first non-empty queue wins (priority processing).
     *
     * @param string|list<string> $queues  Queue name or ordered list of queue names.
     * @param int                 $sleep   Seconds to sleep when no job is available.
     * @param int                 $maxJobs Maximum jobs to process before exiting (0 = unlimited).
     *
     * @return void
     */
    public function work(string|array $queues = 'default', int $sleep = 3, int $maxJobs = 0): void
    {
        $processed = 0;

        while (true) {
            $ran = $this->runNextJob($queues);

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
     * Pop and process the next available job from the given queue(s).
     *
     * When $queues is an array, queues are tried in order and the first
     * non-empty queue is used. Returns true if a job was found and processed
     * (successfully or not), false if all queues were empty.
     *
     * @param string|list<string> $queues Queue name or ordered priority list.
     *
     * @return bool
     */
    public function runNextJob(string|array $queues = 'default'): bool
    {
        $queueList = is_string($queues) ? [$queues] : $queues;

        foreach ($queueList as $queue) {
            $job = $this->queue->pop($queue);

            if ($job !== null) {
                $this->process($job);

                return true;
            }
        }

        return false;
    }

    /**
     * Execute a single job.
     *
     * Increments the attempt counter, calls handle(), and handles any
     * Throwable by either re-queuing (if retries remain) or permanently
     * failing the job.
     *
     * When re-queuing, the backoff delay is applied via Job::withDelay() if
     * the job defines a $backoff array. Non-Job JobInterface implementations
     * fall back to a plain re-push.
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
                $toQueue = $job instanceof Job
                    ? $job->withDelay($job->getRetryDelay($job->getAttempts()))
                    : $job;
                $this->queue->push($toQueue);
            } else {
                $this->queue->failed($job, $e);
            }
        }
    }
}
