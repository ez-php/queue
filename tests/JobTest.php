<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Named concrete Job implementation used only in testAttemptsSerializeSafely.
 * Anonymous classes cannot be unserialized, so a named class is required.
 */
final class SerializableJob extends Job
{
    public function __construct(?string $queue = null, int $delay = 0, int $maxTries = 3)
    {
        if ($queue !== null) {
            $this->queue = $queue;
        }

        $this->delay = $delay;
        $this->maxTries = $maxTries;
    }

    public function handle(): void
    {
    }
}

#[CoversClass(Job::class)]
final class JobTest extends TestCase
{
    private function makeJob(?string $queue = null, int $delay = 0, int $maxTries = 3): Job
    {
        return new class ($queue, $delay, $maxTries) extends Job {
            public function __construct(?string $q, int $d, int $m)
            {
                if ($q !== null) {
                    $this->queue = $q;
                }

                $this->delay = $d;
                $this->maxTries = $m;
            }

            public function handle(): void
            {
            }
        };
    }

    public function testDefaultValues(): void
    {
        $job = $this->makeJob();

        $this->assertSame('default', $job->getQueue());
        $this->assertSame(0, $job->getDelay());
        $this->assertSame(3, $job->getMaxTries());
        $this->assertSame(0, $job->getAttempts());
    }

    public function testCustomProperties(): void
    {
        $job = $this->makeJob('emails', 30, 5);

        $this->assertSame('emails', $job->getQueue());
        $this->assertSame(30, $job->getDelay());
        $this->assertSame(5, $job->getMaxTries());
    }

    public function testIncrementAttempts(): void
    {
        $job = $this->makeJob();

        $this->assertSame(0, $job->getAttempts());
        $job->incrementAttempts();
        $this->assertSame(1, $job->getAttempts());
        $job->incrementAttempts();
        $this->assertSame(2, $job->getAttempts());
    }

    public function testFailIsNoOpByDefault(): void
    {
        $job = $this->makeJob();
        $exception = new \RuntimeException('boom');

        // Must not throw
        $job->fail($exception);
        $this->assertSame('boom', $exception->getMessage());
    }

    public function testFailCanBeOverridden(): void
    {
        $exception = new \RuntimeException('test');

        $job = new class () extends Job {
            /** @var bool */
            public bool $failCalled = false;

            /** @var \Throwable|null */
            public ?\Throwable $receivedException = null;

            public function handle(): void
            {
            }

            public function fail(\Throwable $e): void
            {
                $this->failCalled = true;
                $this->receivedException = $e;
            }
        };

        $job->fail($exception);

        $this->assertTrue($job->failCalled);
        $this->assertSame($exception, $job->receivedException);
    }

    public function testAttemptsSerializeSafely(): void
    {
        $job = new SerializableJob('critical', 5, 2);
        $job->incrementAttempts();

        $copy = unserialize(serialize($job));

        $this->assertInstanceOf(Job::class, $copy);
        $this->assertSame(1, $copy->getAttempts());
        $this->assertSame('critical', $copy->getQueue());
        $this->assertSame(5, $copy->getDelay());
        $this->assertSame(2, $copy->getMaxTries());
    }

    // ─── getRetryDelay ────────────────────────────────────────────────────────

    public function testGetRetryDelayReturnsDelayWhenBackoffEmpty(): void
    {
        $job = $this->makeJob(delay: 5);

        $this->assertSame(5, $job->getRetryDelay(1));
        $this->assertSame(5, $job->getRetryDelay(3));
    }

    public function testGetRetryDelayUsesBackoffArray(): void
    {
        $job = new class () extends Job {
            protected array $backoff = [10, 30, 60];

            public function handle(): void
            {
            }
        };

        $this->assertSame(10, $job->getRetryDelay(1));
        $this->assertSame(30, $job->getRetryDelay(2));
        $this->assertSame(60, $job->getRetryDelay(3));
    }

    public function testGetRetryDelayClampsToLastBackoffEntry(): void
    {
        $job = new class () extends Job {
            protected array $backoff = [10, 30];

            public function handle(): void
            {
            }
        };

        // attempt 5 → clamped to backoff[1] = 30
        $this->assertSame(30, $job->getRetryDelay(5));
    }

    // ─── withDelay ────────────────────────────────────────────────────────────

    public function testWithDelayReturnsCloneWithNewDelay(): void
    {
        $job = $this->makeJob(delay: 0);
        $delayed = $job->withDelay(30);

        $this->assertNotSame($job, $delayed);
        $this->assertSame(0, $job->getDelay());
        $this->assertSame(30, $delayed->getDelay());
    }

    public function testWithDelayPreservesOtherProperties(): void
    {
        $job = $this->makeJob(queue: 'emails', delay: 0, maxTries: 5);
        $delayed = $job->withDelay(60);

        $this->assertSame('emails', $delayed->getQueue());
        $this->assertSame(5, $delayed->getMaxTries());
    }
}
