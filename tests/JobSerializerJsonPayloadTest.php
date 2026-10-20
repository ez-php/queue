<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\JobSerializer;
use EzPhp\Queue\QueueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\QueueJsonPayloadJob;
use Tests\Support\QueuePayloadJob;

/**
 * Opt-in JSON job payloads (JsonPayloadJobInterface).
 *
 * @package Tests
 */
#[CoversClass(JobSerializer::class)]
#[UsesClass(Job::class)]
#[UsesClass(InMemoryDriver::class)]
final class JobSerializerJsonPayloadTest extends TestCase
{
    public function testJsonJobIsStoredAsJsonNotSerialized(): void
    {
        $envelope = JobSerializer::serialize(new QueueJsonPayloadJob(42, ['vip']));

        $this->assertSame('json', $envelope['format'] ?? null);
        $this->assertSame(QueueJsonPayloadJob::class, $envelope['class']);
        $this->assertSame('{"payload":{"order_id":42,"tags":["vip"]},"attempts":0}', $envelope['data']);
    }

    public function testRoundTripRestoresStateAndAttempts(): void
    {
        $job = new QueueJsonPayloadJob(7, ['a', 'b']);
        $job->incrementAttempts();
        $job->incrementAttempts();

        $restored = JobSerializer::unserialize(JobSerializer::serialize($job));

        $this->assertInstanceOf(QueueJsonPayloadJob::class, $restored);
        $this->assertSame(7, $restored->orderId);
        $this->assertSame(['a', 'b'], $restored->tags);
        $this->assertSame(2, $restored->getAttempts());
        $this->assertSame('json', $restored->getQueue());
    }

    public function testSurvivesTheJsonEncodingOfARealDriver(): void
    {
        $driver = new InMemoryDriver();
        $driver->push(new QueueJsonPayloadJob(9));

        $popped = $driver->pop('json');

        $this->assertInstanceOf(QueueJsonPayloadJob::class, $popped);
        $this->assertSame(9, $popped->orderId);
    }

    public function testJsonEnvelopeNamingANonJsonJobIsRejected(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('does not implement');

        JobSerializer::unserialize(['class' => QueuePayloadJob::class, 'data' => '{"payload":{}}', 'format' => 'json']);
    }

    public function testInvalidJsonDataIsRejected(): void
    {
        $this->expectException(QueueException::class);

        JobSerializer::unserialize(['class' => QueueJsonPayloadJob::class, 'data' => 'O:8:"stdClass":0:{}', 'format' => 'json']);
    }

    public function testMissingPayloadIsRejected(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('missing "payload"');

        JobSerializer::unserialize(['class' => QueueJsonPayloadJob::class, 'data' => '{"attempts":1}', 'format' => 'json']);
    }

    public function testSerializedJobsKeepWorkingUnchanged(): void
    {
        $envelope = JobSerializer::serialize(QueuePayloadJob::make());

        $this->assertArrayNotHasKey('format', $envelope);
        $this->assertInstanceOf(QueuePayloadJob::class, JobSerializer::unserialize($envelope));
    }
}
