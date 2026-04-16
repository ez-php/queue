<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Console\WorkCommand;
use EzPhp\Queue\Job;
use EzPhp\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(WorkCommand::class)]
#[UsesClass(Worker::class)]
#[UsesClass(Job::class)]
final class WorkCommandTest extends TestCase
{
    /** @param list<JobInterface> $jobs */
    private function makeQueue(array $jobs = []): QueueInterface
    {
        return new class ($jobs) implements QueueInterface {
            /** @var list<JobInterface> */
            private array $items;

            /** @param list<JobInterface> $jobs */
            public function __construct(array $jobs)
            {
                $this->items = $jobs;
            }

            public function push(JobInterface $job): void
            {
                $this->items[] = $job;
            }

            public function pop(string $queue = 'default'): ?JobInterface
            {
                return array_shift($this->items) ?? null;
            }

            public function size(string $queue = 'default'): int
            {
                return count($this->items);
            }

            public function failed(JobInterface $job, \Throwable $exception): void
            {
            }
        };
    }

    private function makeJob(): Job
    {
        return new class () extends Job {
            public function handle(): void
            {
            }
        };
    }

    /** Captures output from ob_start() and asserts the buffer is a string. */
    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        $output = ob_get_clean();
        $this->assertIsString($output);

        return (string) $output;
    }

    public function testGetName(): void
    {
        $cmd = new WorkCommand(new Worker($this->makeQueue()));
        $this->assertSame('queue:work', $cmd->getName());
    }

    public function testGetDescription(): void
    {
        $cmd = new WorkCommand(new Worker($this->makeQueue()));
        $this->assertStringContainsString('queue', strtolower($cmd->getDescription()));
    }

    public function testGetHelp(): void
    {
        $cmd = new WorkCommand(new Worker($this->makeQueue()));
        $this->assertStringContainsString('queue:work', $cmd->getHelp());
    }

    public function testHandleProcessesJobsAndExits(): void
    {
        $queue = $this->makeQueue([$this->makeJob(), $this->makeJob()]);
        $worker = new Worker($queue);
        $cmd = new WorkCommand($worker);

        $code = 0;
        $output = $this->captureOutput(function () use ($cmd, &$code): void {
            $code = $cmd->handle(['--max-jobs=2', '--sleep=0']);
        });

        $this->assertSame(0, $code);
        $this->assertStringContainsString('default', $output);
        $this->assertSame(0, $queue->size());
    }

    public function testHandleUsesCustomQueueName(): void
    {
        $queue = $this->makeQueue([]);
        $worker = new Worker($queue);
        $cmd = new WorkCommand($worker);

        $output = $this->captureOutput(function () use ($cmd): void {
            $cmd->handle(['emails', '--max-jobs=1', '--sleep=0']);
        });

        $this->assertStringContainsString('emails', $output);
    }

    public function testHandleShowsMaxJobsCount(): void
    {
        $queue = $this->makeQueue([$this->makeJob(), $this->makeJob()]);
        $worker = new Worker($queue);
        $cmd = new WorkCommand($worker);

        $output = $this->captureOutput(function () use ($cmd): void {
            $cmd->handle(['default', '--max-jobs=2', '--sleep=0']);
        });

        $this->assertStringContainsString('Max jobs: 2', $output);
    }

    public function testHandlePrintsStatsSummaryOnExit(): void
    {
        $queue = $this->makeQueue([$this->makeJob(), $this->makeJob()]);
        $worker = new Worker($queue);
        $cmd = new WorkCommand($worker);

        $output = $this->captureOutput(function () use ($cmd): void {
            $cmd->handle(['default', '--max-jobs=2', '--sleep=0']);
        });

        $this->assertStringContainsString('Processed:', $output);
        $this->assertStringContainsString('2', $output);
    }

    public function testHandlePrintsZeroStatsWhenQueueEmpty(): void
    {
        $queue = $this->makeQueue([]);
        $worker = new Worker($queue);
        $cmd = new WorkCommand($worker);

        $output = $this->captureOutput(function () use ($cmd): void {
            $cmd->handle(['default', '--max-jobs=1', '--sleep=0']);
        });

        $this->assertStringContainsString('Processed: 0', $output);
    }
}
