<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Job;
use EzPhp\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * In-memory QueueInterface stub with failure tracking.
 * Defined at file scope so its public properties are visible to PHPStan.
 */
final class StubQueue implements QueueInterface
{
    /** @var list<JobInterface> */
    private array $items;

    /** @var list<array{job: JobInterface, exception: \Throwable}> */
    public array $failures = [];

    /** @param list<JobInterface> $jobs */
    public function __construct(array $jobs = [])
    {
        $this->items = $jobs;
    }

    public function push(JobInterface $job): void
    {
        $this->items[] = $job;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        return array_shift($this->items) ?? null;
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->items);
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
        $this->failures[] = ['job' => $job, 'exception' => $exception];
    }
}

/**
 * Queue stub that captures the delay of each pushed job.
 * Used to verify backoff behaviour in Worker::process().
 */
final class DelayCapturingQueue implements QueueInterface
{
    /** @var list<int> */
    private array $capturedDelays = [];

    private ?JobInterface $item = null;

    public function seed(JobInterface $job): void
    {
        $this->item = $job;
    }

    public function push(JobInterface $job): void
    {
        $this->capturedDelays[] = $job->getDelay();
        $this->item = $job;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $item = $this->item;
        $this->item = null;

        return $item;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->item !== null ? 1 : 0;
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
    }

    /** @return list<int> */
    public function getCapturedDelays(): array
    {
        return $this->capturedDelays;
    }
}

/**
 * Queue stub that routes items by named queue and tracks pushes per queue.
 * Used to test priority-queue behaviour.
 */
final class PrioritizedStubQueue implements QueueInterface
{
    /** @var array<string, list<JobInterface>> */
    private array $buckets = [];

    /** @var list<array{job: JobInterface, exception: \Throwable}> */
    public array $failures = [];

    public function push(JobInterface $job): void
    {
        $this->buckets[$job->getQueue()][] = $job;
    }

    public function enqueue(string $queue, JobInterface $job): void
    {
        $this->buckets[$queue][] = $job;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        if (empty($this->buckets[$queue])) {
            return null;
        }

        return array_shift($this->buckets[$queue]);
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->buckets[$queue] ?? []);
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
        $this->failures[] = ['job' => $job, 'exception' => $exception];
    }
}

/**
 * Job stub with tracking flags and configurable failure behaviour.
 * Defined at file scope so its public properties are visible to PHPStan.
 */
final class TrackingJob extends Job
{
    public bool $handled = false;

    public bool $failCalled = false;

    public function __construct(private readonly bool $throws, int $maxTries = 3)
    {
        $this->maxTries = $maxTries;
    }

    public function handle(): void
    {
        $this->handled = true;

        if ($this->throws) {
            throw new \RuntimeException('job failed');
        }
    }

    public function fail(\Throwable $exception): void
    {
        $this->failCalled = true;
    }
}

#[CoversClass(Worker::class)]
#[UsesClass(Job::class)]
final class WorkerTest extends TestCase
{
    public function testRunNextJobReturnsFalseWhenEmpty(): void
    {
        $worker = new Worker(new StubQueue([]));

        $this->assertFalse($worker->runNextJob());
    }

    public function testRunNextJobReturnsTrueAndExecutesJob(): void
    {
        $job = new TrackingJob(false);
        $worker = new Worker(new StubQueue([$job]));

        $this->assertTrue($worker->runNextJob());
        $this->assertTrue($job->handled);
        $this->assertSame(1, $job->getAttempts());
    }

    public function testSuccessfulJobIsNotRequeued(): void
    {
        $queue = new StubQueue([new TrackingJob(false)]);
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame(0, $queue->size());
    }

    public function testFailingJobWithRetriesRemainingIsRequeued(): void
    {
        $job = new TrackingJob(throws: true, maxTries: 3);
        $queue = new StubQueue([$job]);
        $worker = new Worker($queue);

        $worker->runNextJob();

        // job failed once (attempt=1 < maxTries=3) → re-queued
        $this->assertTrue($job->failCalled);
        $this->assertSame(1, $queue->size());
        $this->assertEmpty($queue->failures);
    }

    public function testFailingJobExhaustedIsMarkedAsFailed(): void
    {
        $job = new TrackingJob(throws: true, maxTries: 1);
        $queue = new StubQueue([$job]);
        $worker = new Worker($queue);

        $worker->runNextJob();

        // attempt=1 >= maxTries=1 → permanently failed
        $this->assertTrue($job->failCalled);
        $this->assertSame(0, $queue->size());
        $this->assertCount(1, $queue->failures);
        $this->assertSame($job, $queue->failures[0]['job']);
        $this->assertSame('job failed', $queue->failures[0]['exception']->getMessage());
    }

    public function testWorkStopsAfterMaxJobs(): void
    {
        $jobs = [
            new TrackingJob(false),
            new TrackingJob(false),
            new TrackingJob(false),
        ];

        $queue = new StubQueue($jobs);
        $worker = new Worker($queue);

        $worker->work(maxJobs: 2, sleep: 0);

        $this->assertSame(1, $queue->size(), 'Third job should still be queued');
        $this->assertTrue($jobs[0]->handled);
        $this->assertTrue($jobs[1]->handled);
        $this->assertFalse($jobs[2]->handled);
    }

    // ─── Priority queues ─────────────────────────────────────────────────────

    public function testPriorityQueuePicksHighestPriorityFirst(): void
    {
        $queue = new PrioritizedStubQueue();
        $highJob = new TrackingJob(false);
        $defaultJob = new TrackingJob(false);

        // High-priority queue has a job; default queue also has one
        $queue->enqueue('high', $highJob);
        $queue->enqueue('default', $defaultJob);

        $worker = new Worker($queue);
        $worker->runNextJob(['high', 'default']);

        $this->assertTrue($highJob->handled, 'High-priority job should run first');
        $this->assertFalse($defaultJob->handled, 'Default job should not run yet');
    }

    public function testPriorityQueueFallsBackToLowerPriority(): void
    {
        $queue = new PrioritizedStubQueue();
        $defaultJob = new TrackingJob(false);

        // High queue is empty; default queue has a job
        $queue->enqueue('default', $defaultJob);

        $worker = new Worker($queue);
        $ran = $worker->runNextJob(['high', 'default']);

        $this->assertTrue($ran);
        $this->assertTrue($defaultJob->handled);
    }

    public function testRunNextJobWithStringQueueStillWorks(): void
    {
        $job = new TrackingJob(false);
        $queue = new StubQueue([$job]);
        $worker = new Worker($queue);

        $this->assertTrue($worker->runNextJob('default'));
        $this->assertTrue($job->handled);
    }

    public function testWorkAcceptsPriorityQueueArray(): void
    {
        $queue = new PrioritizedStubQueue();
        $job1 = new TrackingJob(false);
        $job2 = new TrackingJob(false);

        $queue->enqueue('high', $job1);
        $queue->enqueue('default', $job2);

        $worker = new Worker($queue);
        $worker->work(['high', 'default'], sleep: 0, maxJobs: 2);

        $this->assertTrue($job1->handled);
        $this->assertTrue($job2->handled);
    }

    // ─── Retry backoff ───────────────────────────────────────────────────────

    public function testBackoffDelayIsAppliedOnRequeue(): void
    {
        $queue = new DelayCapturingQueue();

        $job = new class () extends Job {
            protected array $backoff = [10, 30, 60];

            protected int $maxTries = 3;

            public function handle(): void
            {
                throw new \RuntimeException('fail');
            }
        };

        $queue->seed($job);
        $worker = new Worker($queue);
        $worker->runNextJob(); // attempt 1 → fails → re-queued with backoff[0]=10

        // Only the re-queued push is captured (seed() bypasses push())
        $this->assertSame([10], $queue->getCapturedDelays());
    }

    public function testWithoutBackoffFallsBackToDelay(): void
    {
        $queue = new DelayCapturingQueue();

        $job = new class () extends Job {
            protected int $delay = 5;

            protected int $maxTries = 3;

            public function handle(): void
            {
                throw new \RuntimeException('fail');
            }
        };

        $queue->seed($job);
        $worker = new Worker($queue);
        $worker->runNextJob();

        $this->assertSame([5], $queue->getCapturedDelays());
    }

    // ─── Stats tracking ───────────────────────────────────────────────────────

    public function testGetStatsInitiallyAllZero(): void
    {
        $worker = new Worker(new StubQueue());

        $this->assertSame(['processed' => 0, 'retried' => 0, 'failed' => 0], $worker->getStats());
    }

    public function testGetStatsCountsProcessedOnSuccess(): void
    {
        $worker = new Worker(new StubQueue([
            new TrackingJob(false),
            new TrackingJob(false),
        ]));

        $worker->work(maxJobs: 2, sleep: 0);

        $this->assertSame(2, $worker->getStats()['processed']);
        $this->assertSame(0, $worker->getStats()['retried']);
        $this->assertSame(0, $worker->getStats()['failed']);
    }

    public function testGetStatsCountsRetriedOnTransientFailure(): void
    {
        // maxTries=3 → first failure triggers a retry (attempt=1 < 3)
        $job = new TrackingJob(throws: true, maxTries: 3);
        $worker = new Worker(new StubQueue([$job]));

        $worker->runNextJob();

        $stats = $worker->getStats();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame(1, $stats['retried']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testGetStatsCountsFailedOnPermanentFailure(): void
    {
        // maxTries=1 → first failure is permanent
        $job = new TrackingJob(throws: true, maxTries: 1);
        $worker = new Worker(new StubQueue([$job]));

        $worker->runNextJob();

        $stats = $worker->getStats();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['retried']);
        $this->assertSame(1, $stats['failed']);
    }

    public function testGetStatsMixedOutcomes(): void
    {
        $queue = new StubQueue([
            new TrackingJob(false),           // processed
            new TrackingJob(throws: true, maxTries: 1), // permanently failed
            new TrackingJob(false),           // processed
        ]);

        $worker = new Worker($queue);
        $worker->work(maxJobs: 3, sleep: 0);

        $stats = $worker->getStats();
        $this->assertSame(2, $stats['processed']);
        $this->assertSame(0, $stats['retried']);
        $this->assertSame(1, $stats['failed']);
    }

    public function testWorkResetsStatsOnEachCall(): void
    {
        $worker = new Worker(new StubQueue([new TrackingJob(false)]));
        $worker->work(maxJobs: 1, sleep: 0);

        $this->assertSame(1, $worker->getStats()['processed']);

        // Second call with empty queue resets to zero
        $worker = new Worker(new StubQueue([]));
        $worker->work(maxJobs: 1, sleep: 0);

        $this->assertSame(0, $worker->getStats()['processed']);
    }
}
