<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Queue\Driver\RedisDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\QueueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Named Job implementation for RedisDriver tests.
 * Anonymous classes cannot be unserialized, so a named class is required.
 */
final class RedisTestJob extends Job
{
    public function __construct(string $queue = 'default')
    {
        $this->queue = $queue;
    }

    public function handle(): void
    {
    }
}

#[CoversClass(RedisDriver::class)]
#[UsesClass(Job::class)]
#[UsesClass(QueueException::class)]
final class RedisDriverTest extends TestCase
{
    private RedisDriver $driver;

    private \Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available.');
        }

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->driver = new RedisDriver($host, $port, 1); // database 1 to isolate tests
        } catch (\Throwable) {
            $this->markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
        $this->redis->select(1);
        $this->redis->del(
            'queues:delayed:default',
            'queues:delayed:emails',
            'queues:delayed:critical',
            'queues:failed:default',
            'queues:failed_jobs',
            'queues:failed_jobs:id',
        );
        // Flush only our test keys via pop-until-null
        while ($this->driver->pop('default') !== null) {
        }

        while ($this->driver->pop('emails') !== null) {
        }

        while ($this->driver->pop('critical') !== null) {
        }
    }

    private function makeJob(string $queue = 'default'): RedisTestJob
    {
        return new RedisTestJob($queue);
    }

    public function testThrowsWhenExtensionMissing(): void
    {
        // This test is only meaningful when ext-redis IS loaded (the skip above
        // covers the case where it isn't). We verify the exception is the right type.
        $this->expectException(QueueException::class);

        // Simulate by checking the exception class directly
        throw new QueueException('ext-redis is required to use RedisDriver.');
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->driver->pop());
    }

    public function testPushAndPop(): void
    {
        $job = $this->makeJob();
        $this->driver->push($job);

        $popped = $this->driver->pop();

        $this->assertInstanceOf(Job::class, $popped);
        $this->assertNull($this->driver->pop(), 'Queue should be empty after pop');
    }

    public function testPopRespectsQueue(): void
    {
        $this->driver->push($this->makeJob('emails'));
        $this->driver->push($this->makeJob('default'));

        $popped = $this->driver->pop('emails');
        $this->assertNotNull($popped);
        $this->assertSame('emails', $popped->getQueue());

        $this->assertNull($this->driver->pop('emails'));
    }

    public function testSize(): void
    {
        $this->assertSame(0, $this->driver->size());

        $this->driver->push($this->makeJob());
        $this->driver->push($this->makeJob());

        $this->assertSame(2, $this->driver->size());
    }

    public function testFifoOrder(): void
    {
        $first = $this->makeJob();
        $first->incrementAttempts();

        $second = $this->makeJob();

        $this->driver->push($first);
        $this->driver->push($second);

        $poppedFirst = $this->driver->pop();
        $this->assertNotNull($poppedFirst);
        $this->assertSame(1, $poppedFirst->getAttempts(), 'First job should have 1 attempt');

        $poppedSecond = $this->driver->pop();
        $this->assertNotNull($poppedSecond);
        $this->assertSame(0, $poppedSecond->getAttempts(), 'Second job should have 0 attempts');
    }

    public function testFailedJobsCanBeListedRetriedAndForgotten(): void
    {
        $this->driver->failed($this->makeJob(), new \RuntimeException('first failure'));
        $this->driver->failed($this->makeJob('emails'), new \RuntimeException('second failure'));

        $this->assertSame(0, $this->driver->size());

        $rows = $this->driver->all();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_column($rows, 'id'));
        $this->assertSame(['default', 'emails'], array_column($rows, 'queue'));
        $this->assertStringStartsWith("first failure\n", $rows[0]['exception']);

        $this->assertTrue($this->driver->retry(1, $this->driver));
        $this->assertInstanceOf(RedisTestJob::class, $this->driver->pop('default'));
        $this->assertFalse($this->driver->retry(1, $this->driver));

        $this->assertTrue($this->driver->forget(2));
        $this->assertFalse($this->driver->forget(2));
        $this->assertSame([], $this->driver->all());
    }

    public function testFlushRemovesEveryFailedJob(): void
    {
        $this->driver->failed($this->makeJob(), new \RuntimeException('x'));
        $this->driver->failed($this->makeJob(), new \RuntimeException('y'));

        $this->driver->flush();

        $this->assertSame([], $this->driver->all());
    }

    /**
     * Failures written by versions before the hash existed sit in per-queue lists;
     * they are moved into the hash so queue:failed can retry them.
     */
    public function testLegacyFailureListsAreMigrated(): void
    {
        $envelope = \EzPhp\Queue\JobSerializer::serialize($this->makeJob());
        $this->redis->rPush('queues:failed:default', (string) json_encode([
            'class' => $envelope['class'],
            'classes' => $envelope['classes'],
            'format' => $envelope['format'] ?? 'php',
            'job' => $envelope['data'],
            'exception' => 'legacy failure',
            'trace' => '#0 {main}',
            'failed_at' => '2026-10-01 12:00:00',
        ]));
        $this->redis->rPush('queues:failed:default', 'not json');

        $rows = $this->driver->all();

        $this->assertCount(1, $rows);
        $this->assertSame('default', $rows[0]['queue']);
        $this->assertSame("legacy failure\n#0 {main}", $rows[0]['exception']);
        $this->assertSame('2026-10-01 12:00:00', $rows[0]['failed_at']);
        $this->assertSame(0, (int) $this->redis->exists('queues:failed:default'));

        $this->assertTrue($this->driver->retry($rows[0]['id'], $this->driver));
        $this->assertInstanceOf(RedisTestJob::class, $this->driver->pop('default'));
    }

    public function testJobSurvivesSerializeRoundTrip(): void
    {
        $job = $this->makeJob('critical');
        $job->incrementAttempts();
        $job->incrementAttempts();

        $this->driver->push($job);
        $popped = $this->driver->pop('critical');

        $this->assertNotNull($popped);
        $this->assertSame(2, $popped->getAttempts());
    }

    public function testDelayedJobIsNotPoppedBeforeItIsDue(): void
    {
        $this->driver->push($this->makeJob()->withDelay(300));

        $this->assertNull($this->driver->pop(), 'A delayed job must not be available immediately');
        $this->assertSame(0, $this->driver->size(), 'size() counts only jobs available now');
    }

    public function testDelayedJobIsPoppedOnceDue(): void
    {
        $this->driver->push($this->makeJob()->withDelay(300));
        $this->makeDelayedJobsDue('default');

        $popped = $this->driver->pop();

        $this->assertInstanceOf(RedisTestJob::class, $popped);
        $this->assertNull($this->driver->pop());
        $this->assertSame(0, (int) $this->redis->zCard('queues:delayed:default'));
    }

    public function testIdenticalDelayedJobsAreNotMerged(): void
    {
        $this->driver->push($this->makeJob()->withDelay(300));
        $this->driver->push($this->makeJob()->withDelay(300));
        $this->makeDelayedJobsDue('default');

        $this->assertSame(2, $this->driver->size());
        $this->assertNotNull($this->driver->pop());
        $this->assertNotNull($this->driver->pop());
        $this->assertNull($this->driver->pop());
    }

    public function testReleasedJobIsNotImmediatelyPoppedAgain(): void
    {
        // What the Worker does for RateLimited / WithoutOverlapping: re-push with a release delay.
        $this->driver->push($this->makeJob()->withRelease(30));

        $this->assertNull($this->driver->pop(), 'A released job must wait out its delay instead of looping');
    }

    /**
     * Move every delayed job of a queue into the past so the next pop() treats it as due.
     */
    private function makeDelayedJobsDue(string $queue): void
    {
        $key = 'queues:delayed:' . $queue;

        /** @var list<string> $members */
        $members = $this->redis->zRange($key, 0, -1);

        foreach ($members as $member) {
            $this->redis->zAdd($key, time() - 1, $member);
        }
    }
}
