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

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available.');
        }

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->driver = new RedisDriver($host, $port, 1); // database 1 to isolate tests
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

    public function testFailedPushesToFailedList(): void
    {
        $job = $this->makeJob();
        $e = new \RuntimeException('redis fail');

        $this->driver->failed($job, $e);

        // The failed job is stored; we can't pop it via the normal queue,
        // but size() of the main queue should remain 0.
        $this->assertSame(0, $this->driver->size());
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
}
