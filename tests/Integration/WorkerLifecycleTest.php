<?php

declare(strict_types=1);

namespace Tests\Integration;

use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\Worker;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Job that increments a static counter on each execution.
 * Named class required for PHP serialization across worker cycles.
 */
final class CounterJob extends Job
{
    /** @var int */
    public static int $handled = 0;

    /**
     * @param string $queue
     */
    public function __construct(string $queue = 'default')
    {
        $this->queue = $queue;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        self::$handled++;
    }
}

/**
 * Job that appends its sequence number to a static array on execution.
 * Used to verify FIFO ordering through the full serialization cycle.
 */
final class OrderedJob extends Job
{
    /** @var list<int> */
    public static array $executionOrder = [];

    /**
     * @param int $sequence
     */
    public function __construct(private readonly int $sequence)
    {
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        self::$executionOrder[] = $this->sequence;
    }
}

/**
 * Job that always throws, tracks fail() invocation count and the last exception.
 * Static counters survive PHP (de)serialization across worker retry cycles.
 */
final class AlwaysFailJob extends Job
{
    /** @var int */
    public static int $failCallCount = 0;

    /** @var \Throwable|null */
    public static ?\Throwable $lastException = null;

    /**
     * @param int $maxTries
     */
    public function __construct(int $maxTries = 2)
    {
        $this->maxTries = $maxTries;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        throw new RuntimeException('Deliberate failure');
    }

    /**
     * @param \Throwable $exception
     *
     * @return void
     */
    public function fail(\Throwable $exception): void
    {
        self::$failCallCount++;
        self::$lastException = $exception;
    }
}

/**
 * Job that throws only on its first execution attempt.
 * Uses a static counter so the "first attempt" signal survives
 * serialization between retry cycles within the same PHP process.
 */
final class FailOnceJob extends Job
{
    /** @var int */
    public static int $handleCount = 0;

    /**
     * @return void
     */
    public function handle(): void
    {
        self::$handleCount++;

        if (self::$handleCount === 1) {
            throw new RuntimeException('First attempt failure');
        }
    }
}

/**
 * Job that always fails and declares a long backoff to defer retries.
 * Used to verify that DatabaseDriver enforces available_at for retried jobs.
 */
final class BackoffJob extends Job
{
    /**
     * @return void
     */
    public function __construct()
    {
        $this->maxTries = 3;
        $this->backoff = [60, 120]; // long delays → retries deferred into the future
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        throw new RuntimeException('Backoff failure');
    }
}

/**
 * Job that records its label to a static array on execution.
 * Accepts a custom queue so tests can verify priority-queue ordering.
 */
final class PriorityJob extends Job
{
    /** @var list<string> */
    public static array $executionOrder = [];

    /**
     * @param string $queue
     * @param string $label
     */
    public function __construct(string $queue, private readonly string $label)
    {
        $this->queue = $queue;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        self::$executionOrder[] = $this->label;
    }
}

/**
 * Integration tests for the Worker + DatabaseDriver job lifecycle.
 *
 * These tests exercise the full round-trip: PHP serialization → SQL storage →
 * deserialization → execution → retry / failure handling.  A SQLite :memory:
 * database is used so no external infrastructure is required.
 *
 * Scenarios covered:
 *   - Dispatch, pick-up, and execution of a successful job
 *   - Pop-and-delete acknowledgement (successful job removed from queue)
 *   - Empty queue returns false
 *   - Failed job re-queued when retries remain
 *   - Failed job moved to failed_jobs after maxTries exhausted
 *   - fail() callback called on every failed attempt
 *   - Attempt counter preserved through serialization across retry cycles
 *   - Backoff delay applied → retried job deferred (not immediately available)
 *   - Job recovery: succeeds on retry after initial failure
 *   - FIFO ordering preserved through the full DB cycle
 *   - work() stops after maxJobs processed
 *   - work() exits bounded mode when queue drains before limit
 *   - Priority queue: higher-priority queue processed first
 *   - Priority queue: falls back to lower-priority queue when high is empty
 *   - Failed job management: retry, delete, flush via FailedJobRepositoryInterface
 */
#[CoversClass(Worker::class)]
#[CoversClass(DatabaseDriver::class)]
#[UsesClass(Job::class)]
final class WorkerLifecycleTest extends TestCase
{
    private DatabaseDriver $driver;

    private Worker $worker;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = new DatabaseDriver($pdo);
        $this->worker = new Worker($this->driver);

        // Reset static state — each test gets a clean slate
        CounterJob::$handled = 0;
        OrderedJob::$executionOrder = [];
        AlwaysFailJob::$failCallCount = 0;
        AlwaysFailJob::$lastException = null;
        FailOnceJob::$handleCount = 0;
        PriorityJob::$executionOrder = [];
    }

    // ─── Basic dispatch / execute / acknowledge ───────────────────────────────

    /**
     * @return void
     */
    public function testDispatchAndExecuteSuccessfulJob(): void
    {
        $this->driver->push(new CounterJob());

        $processed = $this->worker->runNextJob(['default']);

        $this->assertTrue($processed);
        $this->assertSame(1, CounterJob::$handled);
    }

    /**
     * A successful job is atomically removed from the queue (pop-and-delete).
     * It must not appear in the failed_jobs table.
     *
     * @return void
     */
    public function testSuccessfulJobIsRemovedFromQueueAfterExecution(): void
    {
        $this->driver->push(new CounterJob());
        $this->assertSame(1, $this->driver->size('default'));

        $this->worker->runNextJob(['default']);

        $this->assertSame(0, $this->driver->size('default'));
        $this->assertCount(0, $this->driver->all());
    }

    /**
     * @return void
     */
    public function testEmptyQueueReturnsFalse(): void
    {
        $this->assertFalse($this->worker->runNextJob(['default']));
    }

    // ─── Retry logic ─────────────────────────────────────────────────────────

    /**
     * When a job fails and attempts < maxTries the Worker re-queues it.
     *
     * @return void
     */
    public function testFailingJobIsRequeuedWhenRetriesRemain(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 3));

        $this->worker->runNextJob(['default']); // attempt 1 → fails → re-queued

        $this->assertSame(1, $this->driver->size('default'));
        $this->assertCount(0, $this->driver->all()); // not permanently failed
    }

    /**
     * When attempts == maxTries the Worker moves the job to failed_jobs.
     *
     * @return void
     */
    public function testFailingJobMovedToFailedJobsAfterMaxTriesExhausted(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 2));

        $this->worker->runNextJob(['default']); // attempt 1 → re-queued
        $this->worker->runNextJob(['default']); // attempt 2 → permanently failed

        $this->assertSame(0, $this->driver->size('default'));
        $this->assertCount(1, $this->driver->all());
    }

    /**
     * fail() is called on every failed attempt — not only on the final one.
     *
     * @return void
     */
    public function testFailCallbackIsCalledOnEveryFailedAttempt(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 3));

        $this->worker->runNextJob(['default']); // attempt 1 → fail() called
        $this->worker->runNextJob(['default']); // attempt 2 → fail() called
        $this->worker->runNextJob(['default']); // attempt 3 → fail() called, job permanently failed

        $this->assertSame(3, AlwaysFailJob::$failCallCount);
        $this->assertInstanceOf(RuntimeException::class, AlwaysFailJob::$lastException);
        $this->assertSame('Deliberate failure', AlwaysFailJob::$lastException->getMessage());
    }

    /**
     * The attempt counter is serialised with the job payload and survives
     * the round-trip through the database between worker cycles.
     *
     * @return void
     */
    public function testAttemptCountIsPreservedAcrossRequeue(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 3));

        $this->worker->runNextJob(['default']); // attempt 1 → fails → re-queued

        $requeued = $this->driver->pop('default');

        $this->assertNotNull($requeued);
        $this->assertSame(1, $requeued->getAttempts());
    }

    /**
     * A job that fails once is re-queued and succeeds on the second attempt.
     * After recovery it must not appear in failed_jobs.
     *
     * @return void
     */
    public function testJobRecoveryAfterInitialFailure(): void
    {
        $this->driver->push(new FailOnceJob());

        $this->worker->runNextJob(['default']); // attempt 1 → fails → re-queued
        $this->assertSame(1, $this->driver->size('default'));

        $this->worker->runNextJob(['default']); // attempt 2 → succeeds
        $this->assertSame(0, $this->driver->size('default'));
        $this->assertCount(0, $this->driver->all());
        $this->assertSame(2, FailOnceJob::$handleCount);
    }

    // ─── Backoff delay ───────────────────────────────────────────────────────

    /**
     * When a job defines a $backoff array the Worker applies the per-attempt
     * delay via withDelay().  DatabaseDriver stores the job with a future
     * available_at so it is not immediately returned by size() or pop().
     *
     * @return void
     */
    public function testBackoffRetryJobIsDeferredAndNotImmediatelyAvailable(): void
    {
        $this->driver->push(new BackoffJob());

        $this->worker->runNextJob(['default']); // attempt 1 → fails → re-queued with delay=60

        // available_at is now + 60 s → not counted by size() or returned by pop()
        $this->assertSame(0, $this->driver->size('default'));
        // Also not in failed_jobs — the job is deferred, not permanently failed
        $this->assertCount(0, $this->driver->all());
    }

    // ─── FIFO ordering ───────────────────────────────────────────────────────

    /**
     * Jobs are processed in FIFO order through the full serialization cycle.
     *
     * @return void
     */
    public function testFifoJobProcessingOrder(): void
    {
        $this->driver->push(new OrderedJob(1));
        $this->driver->push(new OrderedJob(2));
        $this->driver->push(new OrderedJob(3));

        $this->worker->work(['default'], sleep: 0, maxJobs: 3);

        $this->assertSame([1, 2, 3], OrderedJob::$executionOrder);
    }

    // ─── work() bounded mode ─────────────────────────────────────────────────

    /**
     * work() stops after processing maxJobs jobs and leaves the rest queued.
     *
     * @return void
     */
    public function testWorkStopsAfterMaxJobsProcessed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->driver->push(new CounterJob());
        }

        $this->worker->work(['default'], sleep: 0, maxJobs: 3);

        $this->assertSame(3, CounterJob::$handled);
        $this->assertSame(2, $this->driver->size('default'));
    }

    /**
     * In bounded mode work() exits when the queue drains before the job limit
     * is reached, rather than sleeping indefinitely.
     *
     * @return void
     */
    public function testWorkExitsBoundedModeWhenQueueDrainsEarly(): void
    {
        $this->driver->push(new CounterJob());
        $this->driver->push(new CounterJob());

        $this->worker->work(['default'], sleep: 0, maxJobs: 10);

        $this->assertSame(2, CounterJob::$handled);
        $this->assertSame(0, $this->driver->size('default'));
    }

    // ─── Priority queues ─────────────────────────────────────────────────────

    /**
     * When multiple queues are provided the Worker polls them in order and
     * processes the first non-empty queue.
     *
     * @return void
     */
    public function testPriorityQueueHighProcessedBeforeLow(): void
    {
        $this->driver->push(new PriorityJob('low', 'low'));
        $this->driver->push(new PriorityJob('high', 'high'));

        $this->worker->runNextJob(['high', 'low']);

        $this->assertSame(['high'], PriorityJob::$executionOrder);
        $this->assertSame(0, $this->driver->size('high'));
        $this->assertSame(1, $this->driver->size('low'));
    }

    /**
     * When the high-priority queue is empty the Worker falls back to the next
     * queue in the list.
     *
     * @return void
     */
    public function testPriorityQueueFallsBackToLowerPriorityWhenHighIsEmpty(): void
    {
        $this->driver->push(new PriorityJob('low', 'low'));

        $ran = $this->worker->runNextJob(['high', 'low']);

        $this->assertTrue($ran);
        $this->assertSame(['low'], PriorityJob::$executionOrder);
    }

    // ─── Failed job management ───────────────────────────────────────────────

    /**
     * A permanently failed job can be moved back to the queue via retry().
     *
     * @return void
     */
    public function testFailedJobCanBeRetriedViaRepository(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 1));
        $this->worker->runNextJob(['default']); // attempt 1 → permanently failed

        $failedJobs = $this->driver->all();
        $this->assertCount(1, $failedJobs);

        $failedId = $failedJobs[0]['id'];
        $this->driver->retry($failedId, $this->driver);

        $this->assertSame(1, $this->driver->size('default'));
        $this->assertCount(0, $this->driver->all());
    }

    /**
     * A single failed job can be removed via forget().
     *
     * @return void
     */
    public function testFailedJobCanBeDeletedViaForget(): void
    {
        $this->driver->push(new AlwaysFailJob(maxTries: 1));
        $this->worker->runNextJob(['default']);

        $failedId = $this->driver->all()[0]['id'];
        $this->driver->forget($failedId);

        $this->assertCount(0, $this->driver->all());
    }

    /**
     * All failed jobs can be wiped in one call via flush().
     *
     * @return void
     */
    public function testAllFailedJobsCanBeFlushedAtOnce(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->driver->push(new AlwaysFailJob(maxTries: 1));
            $this->worker->runNextJob(['default']);
        }

        $this->assertCount(3, $this->driver->all());

        $this->driver->flush();

        $this->assertCount(0, $this->driver->all());
    }
}
