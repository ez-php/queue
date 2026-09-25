<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

require_once __DIR__ . '/MiddlewareFixtures.php';

#[CoversClass(Worker::class)]
#[CoversClass(Job::class)]
#[UsesClass(InMemoryDriver::class)]
final class JobMiddlewarePipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        QueueMwState::reset();
    }

    public function testMiddlewareWrapsHandleOutermostFirst(): void
    {
        $queue = new InMemoryDriver();
        $queue->push(new QueueMwPipelineJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame(['A:before', 'B:before', 'handle', 'B:after', 'A:after'], QueueMwState::$log);
        $this->assertSame(1, $worker->getStats()['processed']);
    }

    public function testJobsWithoutMiddlewareStillRun(): void
    {
        $job = new class () extends Job {
            public function handle(): void
            {
            }
        };

        $this->assertSame([], $job->middleware());
    }

    public function testExceptionInHandlePropagatesThroughMiddlewareAndRetries(): void
    {
        QueueMwState::$throwInHandle = true;
        $queue = new InMemoryDriver();
        $queue->push(new QueueMwPipelineJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        // "after" hooks are skipped because the exception unwinds the pipeline.
        $this->assertSame(['A:before', 'B:before', 'handle'], QueueMwState::$log);
        $this->assertSame(1, $worker->getStats()['retried']);
    }

    public function testExceptionInMiddlewareCountsAsAFailedAttempt(): void
    {
        $queue = new InMemoryDriver();
        $queue->push(new QueueMwThrowingMiddlewareJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame([], QueueMwState::$log);
        $this->assertSame(['processed' => 0, 'retried' => 1, 'failed' => 0], $worker->getStats());
    }

    public function testReleasedJobIsPushedBackWithDelayAndKeepsItsAttempt(): void
    {
        $queue = new QueueMwCapturingQueue();
        $queue->seed(new QueueMwReleasedJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame([], QueueMwState::$log, 'handle() must not run for a released job');
        $this->assertCount(1, $queue->pushed);
        $this->assertSame(30, $queue->pushed[0]->getDelay());
        $this->assertSame(0, $queue->pushed[0]->getAttempts(), 'a release must not consume an attempt');
        $this->assertSame(['processed' => 0, 'retried' => 0, 'failed' => 0], $worker->getStats());
    }

    public function testZeroSecondReleaseIsPushedBackWithAtLeastOneSecondDelay(): void
    {
        // releaseAfter(0) would make a still-blocked job immediately available again,
        // so the Worker would pop it straight back in a tight loop on every driver.
        $queue = new QueueMwCapturingQueue();
        $queue->seed(new QueueMwZeroReleasedJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertCount(1, $queue->pushed);
        $this->assertSame(1, $queue->pushed[0]->getDelay());
        $this->assertSame(0, $queue->pushed[0]->getAttempts());
    }

    public function testReleaseRequestIsClearedAfterPushing(): void
    {
        $job = new QueueMwReleasedJob();
        $job->releaseAfter(5);

        $this->assertSame(5, $job->pullReleaseDelay());
        $this->assertNull($job->pullReleaseDelay());
    }

    public function testWithoutOverlappingRunsAndFreesTheLock(): void
    {
        $queue = new QueueMwCapturingQueue();
        $queue->seed(new QueueMwNoOverlapJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame(['handle'], QueueMwState::$log);
        $this->assertSame(1, $worker->getStats()['processed']);
        $this->assertTrue(QueueMwNoOverlapJob::locks()->acquire('overlap:reports', 60), 'lock must be free again');
    }

    public function testWithoutOverlappingReleasesTheJobWhileAnotherHoldsTheLock(): void
    {
        QueueMwNoOverlapJob::locks()->acquire('overlap:reports', 60);
        $queue = new QueueMwCapturingQueue();
        $queue->seed(new QueueMwNoOverlapJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame([], QueueMwState::$log);
        $this->assertCount(1, $queue->pushed);
        $this->assertSame(15, $queue->pushed[0]->getDelay());
        $this->assertSame(0, $queue->pushed[0]->getAttempts());
        $this->assertFalse(QueueMwNoOverlapJob::locks()->acquire('overlap:reports', 60), 'the other holder keeps the lock');
    }

    public function testWithoutOverlappingFreesTheLockWhenHandleThrows(): void
    {
        QueueMwState::$throwInHandle = true;
        $queue = new QueueMwCapturingQueue();
        $queue->seed(new QueueMwNoOverlapJob());
        $worker = new Worker($queue);

        $worker->runNextJob();

        $this->assertSame(1, $worker->getStats()['retried']);
        $this->assertTrue(QueueMwNoOverlapJob::locks()->acquire('overlap:reports', 60));
    }

    public function testRateLimitedLetsJobsThroughUntilTheBudgetIsSpent(): void
    {
        $queue = new QueueMwCapturingQueue();

        foreach (range(1, 3) as $ignored) {
            $queue->seed(new QueueMwRateLimitedJob());
        }

        $worker = new Worker($queue);

        while ($worker->runNextJob()) {
        }

        $this->assertSame(['handle', 'handle'], QueueMwState::$log, 'budget is 2 per window');
        $this->assertSame(2, $worker->getStats()['processed']);
        $this->assertCount(1, $queue->pushed);
        $this->assertGreaterThanOrEqual(1, $queue->pushed[0]->getDelay());
        $this->assertSame(0, $queue->pushed[0]->getAttempts());
    }
}
