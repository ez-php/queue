<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\Lock\InMemoryJobLock;
use EzPhp\Queue\ShouldBeUnique;
use EzPhp\Queue\UniqueQueue;
use EzPhp\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

final class QueueUniqueJob extends Job implements ShouldBeUnique
{
    public static bool $fail = false;

    public function __construct(private readonly string $id, int $maxTries = 2)
    {
        $this->maxTries = $maxTries;
    }

    public function handle(): void
    {
        if (self::$fail) {
            throw new \RuntimeException('unique job failed');
        }
    }

    public function uniqueId(): string
    {
        return 'report:' . $this->id;
    }

    public function uniqueFor(): int
    {
        return 60;
    }
}

final class QueuePlainJob extends Job
{
    public function handle(): void
    {
    }
}

#[CoversClass(UniqueQueue::class)]
#[UsesClass(Job::class)]
#[UsesClass(Worker::class)]
#[UsesClass(InMemoryDriver::class)]
#[UsesClass(InMemoryJobLock::class)]
final class UniqueQueueTest extends TestCase
{
    private InMemoryDriver $inner;

    private InMemoryJobLock $locks;

    private UniqueQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        QueueUniqueJob::$fail = false;
        $this->inner = new InMemoryDriver();
        $this->locks = new InMemoryJobLock();
        $this->queue = new UniqueQueue($this->inner, $this->locks);
    }

    public function testDuplicateWhileQueuedIsDropped(): void
    {
        $this->queue->push(new QueueUniqueJob('1'));
        $this->queue->push(new QueueUniqueJob('1'));

        $this->assertSame(1, $this->queue->size());
    }

    public function testDifferentIdsAreBothQueued(): void
    {
        $this->queue->push(new QueueUniqueJob('1'));
        $this->queue->push(new QueueUniqueJob('2'));

        $this->assertSame(2, $this->queue->size());
    }

    public function testNonUniqueJobsAlwaysPassThrough(): void
    {
        $this->queue->push(new QueuePlainJob());
        $this->queue->push(new QueuePlainJob());

        $this->assertSame(2, $this->queue->size());
    }

    public function testDuplicateIsStillDroppedWhileTheFirstIsRunning(): void
    {
        $this->queue->push(new QueueUniqueJob('1'));
        $popped = $this->queue->pop();
        $this->assertNotNull($popped);

        $this->queue->push(new QueueUniqueJob('1'));

        $this->assertSame(0, $this->queue->size());
    }

    public function testWorkerFreesTheLockAfterSuccessSoTheJobCanBeQueuedAgain(): void
    {
        $worker = new Worker($this->queue, $this->locks);
        $this->queue->push(new QueueUniqueJob('1'));

        $worker->runNextJob();
        $this->queue->push(new QueueUniqueJob('1'));

        $this->assertSame(1, $this->queue->size());
    }

    public function testLockSurvivesRetriesAndIsFreedOnPermanentFailure(): void
    {
        QueueUniqueJob::$fail = true;
        $worker = new Worker($this->queue, $this->locks);
        $this->queue->push(new QueueUniqueJob('1', maxTries: 2));

        $worker->runNextJob();
        $this->assertSame(1, $worker->getStats()['retried']);

        $this->queue->push(new QueueUniqueJob('1', maxTries: 2));
        $this->assertSame(1, $this->queue->size(), 'only the retry is queued; the duplicate dispatch is dropped');
    }

    public function testPermanentFailureFreesTheLock(): void
    {
        QueueUniqueJob::$fail = true;
        $worker = new Worker($this->queue, $this->locks);
        $this->queue->push(new QueueUniqueJob('1', maxTries: 1));

        $worker->runNextJob();
        $this->assertSame(1, $worker->getStats()['failed']);

        $this->queue->push(new QueueUniqueJob('1', maxTries: 1));
        $this->assertSame(1, $this->queue->size());
    }

    public function testWithoutWorkerLockTheJobStaysBlocked(): void
    {
        $worker = new Worker($this->queue);
        $this->queue->push(new QueueUniqueJob('1'));

        $worker->runNextJob();
        $this->queue->push(new QueueUniqueJob('1'));

        $this->assertSame(0, $this->queue->size());
    }

    public function testFailedIsForwardedToTheInnerQueue(): void
    {
        $job = new QueuePlainJob();

        $this->queue->failed($job, new \RuntimeException('x'));

        $this->assertCount(1, $this->inner->failedJobs());
    }
}
