<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Contracts\JobInterface;
use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Job fixture used across the in-memory driver tests.
 * A named class is required — anonymous classes cannot be unserialized.
 */
final class InMemoryTestJob extends Job
{
    public function __construct(public string $marker = 'x', string $queue = 'default', int $delay = 0)
    {
        $this->queue = $queue;
        $this->delay = $delay;
    }

    public function handle(): void
    {
    }
}

/**
 * Class InMemoryDriverTest
 *
 * Covers the same contract surface as DatabaseDriverTest, without MySQL.
 *
 * @package Tests\Driver
 */
#[CoversClass(InMemoryDriver::class)]
#[UsesClass(Job::class)]
final class InMemoryDriverTest extends TestCase
{
    private InMemoryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new InMemoryDriver();
    }

    // ── push / pop ────────────────────────────────────────────────────────────

    public function testPopReturnsNullOnEmptyQueue(): void
    {
        $this->assertNull($this->driver->pop());
    }

    public function testPushThenPopReturnsTheJob(): void
    {
        $this->driver->push(new InMemoryTestJob('hello'));

        $job = $this->driver->pop();

        $this->assertInstanceOf(InMemoryTestJob::class, $job);
        $this->assertSame('hello', $job->marker);
    }

    public function testPopRemovesTheJob(): void
    {
        $this->driver->push(new InMemoryTestJob());

        $this->driver->pop();

        $this->assertNull($this->driver->pop());
    }

    public function testJobsArePoppedInFifoOrder(): void
    {
        $this->driver->push(new InMemoryTestJob('first'));
        $this->driver->push(new InMemoryTestJob('second'));

        $first = $this->driver->pop();
        $second = $this->driver->pop();

        $this->assertInstanceOf(InMemoryTestJob::class, $first);
        $this->assertInstanceOf(InMemoryTestJob::class, $second);
        $this->assertSame('first', $first->marker);
        $this->assertSame('second', $second->marker);
    }

    // ── queue isolation ───────────────────────────────────────────────────────

    public function testPopOnlyReturnsJobsFromTheRequestedQueue(): void
    {
        $this->driver->push(new InMemoryTestJob('mail-job', 'mail'));

        $this->assertNull($this->driver->pop('default'));

        $popped = $this->driver->pop('mail');
        $this->assertInstanceOf(InMemoryTestJob::class, $popped);
        $this->assertSame('mail-job', $popped->marker);
    }

    public function testPushHonoursTheJobsOwnQueue(): void
    {
        $this->driver->push(new InMemoryTestJob('x', 'reports'));

        $this->assertSame(0, $this->driver->size('default'));
        $this->assertSame(1, $this->driver->size('reports'));
    }

    // ── delay ─────────────────────────────────────────────────────────────────

    public function testDelayedJobIsNotPoppedBeforeItsTime(): void
    {
        $this->driver->push(new InMemoryTestJob('x', 'default', 60));

        $this->assertNull($this->driver->pop());
    }

    public function testDelayedJobIsNotCountedBySize(): void
    {
        $this->driver->push(new InMemoryTestJob('x', 'default', 60));

        $this->assertSame(0, $this->driver->size());
    }

    public function testZeroDelayJobIsImmediatelyAvailable(): void
    {
        $this->driver->push(new InMemoryTestJob());

        $this->assertSame(1, $this->driver->size());
        $this->assertNotNull($this->driver->pop());
    }

    public function testAvailableJobIsPoppedBeforeADelayedOne(): void
    {
        $this->driver->push(new InMemoryTestJob('delayed', 'default', 60));
        $this->driver->push(new InMemoryTestJob('ready'));

        $popped = $this->driver->pop();

        $this->assertInstanceOf(InMemoryTestJob::class, $popped);
        $this->assertSame('ready', $popped->marker);
    }

    // ── size ──────────────────────────────────────────────────────────────────

    public function testSizeIsZeroOnEmptyQueue(): void
    {
        $this->assertSame(0, $this->driver->size());
    }

    public function testSizeCountsPushedJobs(): void
    {
        $this->driver->push(new InMemoryTestJob());
        $this->driver->push(new InMemoryTestJob());

        $this->assertSame(2, $this->driver->size());
    }

    public function testSizeDecrementsAfterPop(): void
    {
        $this->driver->push(new InMemoryTestJob());
        $this->driver->push(new InMemoryTestJob());
        $this->driver->pop();

        $this->assertSame(1, $this->driver->size());
    }

    // ── failed ────────────────────────────────────────────────────────────────

    public function testFailedRecordsTheJob(): void
    {
        $job = new InMemoryTestJob('boom');
        $this->driver->failed($job, new \RuntimeException('it broke'));

        $failed = $this->driver->failedJobs();

        $this->assertCount(1, $failed);
        $this->assertSame('default', $failed[0]['queue']);
        $this->assertSame('it broke', $failed[0]['exception']);
        $this->assertInstanceOf(InMemoryTestJob::class, $failed[0]['job']);
    }

    public function testFailedJobsIsEmptyByDefault(): void
    {
        $this->assertSame([], $this->driver->failedJobs());
    }

    public function testFailedDoesNotAffectQueueSize(): void
    {
        $this->driver->failed(new InMemoryTestJob(), new \RuntimeException('x'));

        $this->assertSame(0, $this->driver->size());
    }

    // ── serialization fidelity ────────────────────────────────────────────────

    /**
     * The driver round-trips jobs through serialize()/unserialize() exactly as
     * DatabaseDriver and RedisDriver do, so a popped job is a copy and later
     * mutations to the pushed instance are not visible.
     */
    public function testPoppedJobIsACopyNotTheSameInstance(): void
    {
        $job = new InMemoryTestJob('original');
        $this->driver->push($job);

        $job->marker = 'mutated after push';

        $popped = $this->driver->pop();

        $this->assertInstanceOf(InMemoryTestJob::class, $popped);
        $this->assertNotSame($job, $popped);
        $this->assertSame('original', $popped->marker);
    }

    // ── test-support helpers ──────────────────────────────────────────────────

    public function testFlushClearsQueuedAndFailedJobs(): void
    {
        $this->driver->push(new InMemoryTestJob());
        $this->driver->failed(new InMemoryTestJob(), new \RuntimeException('x'));

        $this->driver->flush();

        $this->assertSame(0, $this->driver->size());
        $this->assertSame([], $this->driver->failedJobs());
    }

    public function testStoreIsPerInstance(): void
    {
        $this->driver->push(new InMemoryTestJob());

        $this->assertSame(0, (new InMemoryDriver())->size());
    }

    public function testDriverImplementsQueueInterface(): void
    {
        $this->assertInstanceOf(\EzPhp\Contracts\QueueInterface::class, $this->driver);
    }

    public function testPoppedJobIsAJobInterface(): void
    {
        $this->driver->push(new InMemoryTestJob());

        $this->assertInstanceOf(JobInterface::class, $this->driver->pop());
    }
}
