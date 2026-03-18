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
}
