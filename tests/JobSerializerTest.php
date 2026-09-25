<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Queue\Job;
use EzPhp\Queue\JobSerializer;
use EzPhp\Queue\QueueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\QueuePayloadAddressBook;
use Tests\Support\QueuePayloadJob;
use Tests\Support\QueuePayloadPriority;
use Tests\Support\QueuePayloadRecipient;

/**
 * Class JobSerializerTest
 *
 * @package Tests
 */
#[CoversClass(JobSerializer::class)]
#[UsesClass(Job::class)]
final class JobSerializerTest extends TestCase
{
    public function testEnvelopeListsEveryClassInThePayload(): void
    {
        $envelope = JobSerializer::serialize(QueuePayloadJob::make());

        $this->assertSame(QueuePayloadJob::class, $envelope['class']);
        $this->assertContains(QueuePayloadJob::class, $envelope['classes']);
        $this->assertContains(QueuePayloadRecipient::class, $envelope['classes']);
        $this->assertContains(QueuePayloadAddressBook::class, $envelope['classes']);
        $this->assertContains(QueuePayloadPriority::class, $envelope['classes']);
    }

    public function testNestedObjectsAndEnumsSurviveTheRoundTrip(): void
    {
        $job = JobSerializer::unserialize(JobSerializer::serialize(QueuePayloadJob::make('x@example.com')));

        $this->assertInstanceOf(QueuePayloadJob::class, $job);
        $this->assertInstanceOf(QueuePayloadRecipient::class, $job->recipient);
        $this->assertSame('x@example.com', $job->recipient->address);
        $this->assertSame(['x@example.com'], $job->recipient->book->entries);
        $this->assertSame(QueuePayloadPriority::High, $job->priority);
    }

    public function testChainedJobsSurviveTheRoundTrip(): void
    {
        $first = QueuePayloadJob::make('first@example.com')->withChain([QueuePayloadJob::make('second@example.com')]);

        $restored = JobSerializer::unserialize(JobSerializer::serialize($first));

        $this->assertInstanceOf(QueuePayloadJob::class, $restored);
        $next = $restored->nextInChain();
        $this->assertInstanceOf(QueuePayloadJob::class, $next);
        $this->assertSame('second@example.com', $next->recipient->address);
    }

    public function testLegacyEnvelopeWithoutClassListStillRestoresFlatJobs(): void
    {
        $envelope = JobSerializer::serialize(new SerializerFlatJob());
        unset($envelope['classes']);

        $this->assertInstanceOf(SerializerFlatJob::class, JobSerializer::unserialize($envelope));
    }

    public function testClassesMissingFromTheListAreNotInstantiated(): void
    {
        $envelope = JobSerializer::serialize(QueuePayloadJob::make());
        $envelope['classes'] = [QueuePayloadJob::class];

        $this->expectException(\TypeError::class);

        JobSerializer::unserialize($envelope);
    }

    public function testMalformedEnvelopeThrows(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Invalid job payload envelope.');

        JobSerializer::unserialize(['class' => QueuePayloadJob::class]);
    }

    public function testPayloadThatIsNotAJobThrows(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not a JobInterface');

        JobSerializer::unserialize([
            'class' => \ArrayObject::class,
            'classes' => [\ArrayObject::class],
            'data' => serialize(new \ArrayObject([1])),
        ]);
    }

    public function testUnserializableJobThrowsQueueException(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Job cannot be serialized');

        JobSerializer::serialize(new SerializerClosureJob(static fn (): int => 1));
    }
}

/**
 * Job without nested objects.
 */
final class SerializerFlatJob extends Job
{
    public function handle(): void
    {
    }
}

/**
 * Job holding a closure, which serialize() rejects.
 */
final class SerializerClosureJob extends Job
{
    public function __construct(public readonly \Closure $callback)
    {
    }

    public function handle(): void
    {
    }
}
