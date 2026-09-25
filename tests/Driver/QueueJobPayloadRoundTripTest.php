<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Driver\RedisDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\JobSerializer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\QueuePayloadJob;
use Tests\Support\QueuePayloadPriority;
use Tests\TestCase;

/**
 * Push/pop a job with nested objects, an enum and a chain through every driver.
 *
 * Regression test: pop() used to restrict allowed_classes to the job class only,
 * which turned nested objects into __PHP_Incomplete_Class (TypeError on typed
 * properties) for SendMailableJob, AsyncEventJob, chains, ...
 *
 * @package Tests\Driver
 */
#[CoversClass(InMemoryDriver::class)]
#[CoversClass(DatabaseDriver::class)]
#[CoversClass(RedisDriver::class)]
#[UsesClass(JobSerializer::class)]
#[UsesClass(Job::class)]
final class QueueJobPayloadRoundTripTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['memory' => ['memory'], 'database' => ['database'], 'redis' => ['redis']];
    }

    #[DataProvider('drivers')]
    public function testJobWithNestedObjectsSurvivesPushAndPop(string $driver): void
    {
        $queue = $this->makeDriver($driver);
        $queue->push(QueuePayloadJob::make('round@example.com'));

        $job = $queue->pop();

        $this->assertInstanceOf(QueuePayloadJob::class, $job);
        $this->assertSame('round@example.com', $job->recipient->address);
        $this->assertSame(['round@example.com'], $job->recipient->book->entries);
        $this->assertSame(QueuePayloadPriority::High, $job->priority);
    }

    #[DataProvider('drivers')]
    public function testChainSurvivesPushAndPop(string $driver): void
    {
        $queue = $this->makeDriver($driver);
        $queue->push(QueuePayloadJob::make('first@example.com')->withChain([QueuePayloadJob::make('second@example.com')]));

        $job = $queue->pop();

        $this->assertInstanceOf(QueuePayloadJob::class, $job);
        $next = $job->nextInChain();
        $this->assertInstanceOf(QueuePayloadJob::class, $next);
        $this->assertSame('second@example.com', $next->recipient->address);
    }

    public function testFailedJobWithNestedObjectsCanBeRetried(): void
    {
        $driver = new DatabaseDriver(new PDO('sqlite::memory:'));
        $driver->failed(QueuePayloadJob::make('retry@example.com'), new \RuntimeException('boom'));

        $rows = $driver->all();
        $this->assertCount(1, $rows);

        $target = new InMemoryDriver();
        $this->assertTrue($driver->retry((int) $rows[0]['id'], $target));

        $job = $target->pop();
        $this->assertInstanceOf(QueuePayloadJob::class, $job);
        $this->assertSame('retry@example.com', $job->recipient->address);
    }

    private function makeDriver(string $driver): QueueInterface
    {
        if ($driver === 'memory') {
            return new InMemoryDriver();
        }

        if ($driver === 'database') {
            return new DatabaseDriver(new PDO('sqlite::memory:'));
        }

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available.');
        }

        try {
            $redis = new RedisDriver((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('REDIS_PORT') ?: 6379), 1);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }

        while ($redis->pop() !== null) {
            // drain leftovers from earlier runs
        }

        return $redis;
    }
}
