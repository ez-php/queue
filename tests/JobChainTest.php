<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Job;
use EzPhp\Queue\JobChain;
use EzPhp\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

final class QueueChainStep extends Job
{
    /** @var list<string> */
    public static array $ran = [];

    public static ?string $failOn = null;

    public function __construct(private readonly string $name)
    {
        $this->maxTries = 1;
    }

    public function handle(): void
    {
        if (self::$failOn === $this->name) {
            throw new \RuntimeException('step failed');
        }

        self::$ran[] = $this->name;
    }
}

#[CoversClass(JobChain::class)]
#[CoversClass(Job::class)]
#[UsesClass(Worker::class)]
#[UsesClass(InMemoryDriver::class)]
final class JobChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        QueueChainStep::$ran = [];
        QueueChainStep::$failOn = null;
    }

    public function testDispatchPushesOnlyTheFirstJob(): void
    {
        $queue = new InMemoryDriver();

        JobChain::of(new QueueChainStep('a'), new QueueChainStep('b'), new QueueChainStep('c'))->dispatch($queue);

        $this->assertSame(1, $queue->size());
    }

    public function testJobsRunInOrderEachAfterThePreviousSucceeded(): void
    {
        $queue = new InMemoryDriver();
        JobChain::of(new QueueChainStep('a'), new QueueChainStep('b'), new QueueChainStep('c'))->dispatch($queue);
        $worker = new Worker($queue);

        $worker->runNextJob();
        $this->assertSame(['a'], QueueChainStep::$ran);
        $this->assertSame(1, $queue->size(), 'b is queued only after a succeeded');

        $worker->runNextJob();
        $worker->runNextJob();

        $this->assertSame(['a', 'b', 'c'], QueueChainStep::$ran);
        $this->assertSame(0, $queue->size());
        $this->assertSame(3, $worker->getStats()['processed']);
    }

    public function testChainStopsWhenAJobFailsForGood(): void
    {
        QueueChainStep::$failOn = 'b';
        $queue = new InMemoryDriver();
        JobChain::of(new QueueChainStep('a'), new QueueChainStep('b'), new QueueChainStep('c'))->dispatch($queue);
        $worker = new Worker($queue);

        while ($worker->runNextJob()) {
        }

        $this->assertSame(['a'], QueueChainStep::$ran);
        $this->assertSame(1, $worker->getStats()['failed']);
        $this->assertSame(0, $queue->size(), 'c must never be queued');
    }

    public function testSingleJobChainBehavesLikeAPlainPush(): void
    {
        $queue = new InMemoryDriver();
        JobChain::of(new QueueChainStep('only'))->dispatch($queue);

        (new Worker($queue))->runNextJob();

        $this->assertSame(['only'], QueueChainStep::$ran);
        $this->assertSame(0, $queue->size());
    }

    public function testJobWithoutChainHasNoNextJob(): void
    {
        $this->assertNull((new QueueChainStep('x'))->nextInChain());
    }

    public function testWithChainDoesNotMutateTheOriginal(): void
    {
        $original = new QueueChainStep('a');
        $chained = $original->withChain([new QueueChainStep('b')]);

        $this->assertNull($original->nextInChain());
        $this->assertNotNull($chained->nextInChain());
    }
}
