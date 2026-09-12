<?php

declare(strict_types=1);

namespace EzPhp\Queue\Driver;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\QueueException;
use Throwable;

/**
 * Class InMemoryDriver
 *
 * Holds queued jobs in a PHP array for the lifetime of the process. Exists so
 * that code depending on {@see QueueInterface} can be tested without MySQL or
 * Redis — `DatabaseDriver` and `RedisDriver` are the only other options, and
 * both need external infrastructure.
 *
 * Matches the persistent drivers' observable semantics: jobs are stored per
 * queue, `getDelay()` is honoured via an availability timestamp, `pop()` takes
 * the earliest available job and removes it, and `size()` counts only jobs that
 * are currently available.
 *
 * **Not for production.** Nothing is persisted and nothing is shared between
 * processes, so a job pushed in one request is invisible to a worker in another.
 *
 * @package EzPhp\Queue\Driver
 */
final class InMemoryDriver implements QueueInterface
{
    /**
     * Queued envelopes, keyed by queue name.
     *
     * @var array<string, list<array{class: class-string, data: string, available_at: int, seq: int}>>
     */
    private array $queues = [];

    /**
     * Permanently failed jobs, in the order they failed.
     *
     * @var list<array{queue: string, job: JobInterface, exception: string}>
     */
    private array $failed = [];

    /**
     * Monotonic counter giving pushes a stable FIFO order within the same second.
     */
    private int $sequence = 0;

    /**
     * Push a job onto its configured queue.
     *
     * The job is serialized on push, exactly as the persistent drivers do. This
     * is deliberate: storing the object by reference would be faster, but a job
     * that cannot be serialized would then pass its tests here and only fail
     * against the real driver in production.
     *
     * @param JobInterface $job
     *
     * @throws QueueException When the job cannot be serialized.
     *
     * @return void
     */
    public function push(JobInterface $job): void
    {
        try {
            $data = serialize($job);
        } catch (Throwable $e) {
            throw new QueueException('Job cannot be serialized: ' . $e->getMessage(), 0, $e);
        }

        $this->queues[$job->getQueue()][] = [
            'class' => $job::class,
            'data' => $data,
            'available_at' => time() + $job->getDelay(),
            'seq' => $this->sequence++,
        ];
    }

    /**
     * Pop and return the next available job, or null when none is ready.
     *
     * Ordering matches DatabaseDriver: earliest `available_at` first, ties broken
     * by push order.
     *
     * @param string $queue
     *
     * @throws QueueException When the stored payload cannot be restored.
     *
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $entries = $this->queues[$queue] ?? [];
        $now = time();
        $bestIndex = null;

        foreach ($entries as $index => $entry) {
            if ($entry['available_at'] > $now) {
                continue;
            }

            if ($bestIndex === null
                || $entry['available_at'] < $entries[$bestIndex]['available_at']
                || ($entry['available_at'] === $entries[$bestIndex]['available_at']
                    && $entry['seq'] < $entries[$bestIndex]['seq'])
            ) {
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null) {
            return null;
        }

        $entry = $entries[$bestIndex];
        unset($entries[$bestIndex]);
        $this->queues[$queue] = array_values($entries);

        // Restrict deserialization to the concrete class recorded at push time,
        // mirroring DatabaseDriver's defence against gadget-chain injection.
        /** @var mixed $job */
        $job = unserialize($entry['data'], ['allowed_classes' => [$entry['class']]]);

        if (!$job instanceof JobInterface) {
            throw new QueueException('Deserialized payload is not a JobInterface instance.');
        }

        return $job;
    }

    /**
     * Return the number of jobs currently available on the queue.
     *
     * Delayed jobs whose time has not come are excluded, matching DatabaseDriver.
     *
     * @param string $queue
     *
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        $now = time();
        $count = 0;

        foreach ($this->queues[$queue] ?? [] as $entry) {
            if ($entry['available_at'] <= $now) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Record a permanently failed job.
     *
     * @param JobInterface $job
     * @param Throwable    $exception
     *
     * @return void
     */
    public function failed(JobInterface $job, Throwable $exception): void
    {
        $this->failed[] = [
            'queue' => $job->getQueue(),
            'job' => $job,
            'exception' => $exception->getMessage(),
        ];
    }

    /**
     * Return every recorded failure, in order.
     *
     * Test-support only — the persistent drivers expose failures through
     * `FailedJobRepositoryInterface` and a database table instead.
     *
     * @return list<array{queue: string, job: JobInterface, exception: string}>
     */
    public function failedJobs(): array
    {
        return $this->failed;
    }

    /**
     * Discard all queued and failed jobs.
     *
     * Test-support only — useful in `tearDown()` when the driver is shared.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->queues = [];
        $this->failed = [];
    }
}
