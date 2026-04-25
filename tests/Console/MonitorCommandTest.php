<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Console\MonitorCommand;
use EzPhp\Queue\FailedJobRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

// ─── Stubs ───────────────────────────────────────────────────────────────────

/**
 * Queue stub with configurable per-queue sizes, no failed-job store.
 */
final class MonitorStubQueue implements QueueInterface
{
    /** @param array<string, int> $sizes */
    public function __construct(private readonly array $sizes = [])
    {
    }

    public function push(JobInterface $job): void
    {
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->sizes[$queue] ?? 0;
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
    }
}

/**
 * Queue stub that also implements FailedJobRepositoryInterface.
 * Defined independently (not extending MonitorStubQueue) to keep it non-final.
 */
final class MonitorStubQueueWithFailed implements QueueInterface, FailedJobRepositoryInterface
{
    /**
     * @param array<string, int> $sizes
     * @param int                $failedCount
     */
    public function __construct(
        private readonly array $sizes,
        private readonly int $failedCount,
    ) {
    }

    public function push(JobInterface $job): void
    {
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->sizes[$queue] ?? 0;
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
    }

    /**
     * @return list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}> */
        return array_fill(0, $this->failedCount, [
            'id' => 1,
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'error',
            'failed_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function retry(int $id, QueueInterface $queue): bool
    {
        return false;
    }

    public function forget(int $id): bool
    {
        return false;
    }

    public function flush(): void
    {
    }
}

// ─── Tests ───────────────────────────────────────────────────────────────────

#[CoversClass(MonitorCommand::class)]
final class MonitorCommandTest extends TestCase
{
    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        $this->assertIsString($out);

        return (string) $out;
    }

    public function testGetName(): void
    {
        $cmd = new MonitorCommand(new MonitorStubQueue());
        $this->assertSame('queue:monitor', $cmd->getName());
    }

    public function testGetDescription(): void
    {
        $cmd = new MonitorCommand(new MonitorStubQueue());
        $this->assertStringContainsString('monitor', strtolower($cmd->getDescription()));
    }

    public function testGetHelp(): void
    {
        $cmd = new MonitorCommand(new MonitorStubQueue());
        $this->assertStringContainsString('queue:monitor', $cmd->getHelp());
    }

    public function testOutputContainsQueueName(): void
    {
        $queue = new MonitorStubQueue(['default' => 3]);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle([]));

        $this->assertStringContainsString('default', $output);
    }

    public function testOutputContainsPendingCount(): void
    {
        $queue = new MonitorStubQueue(['default' => 7]);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle([]));

        $this->assertStringContainsString('7', $output);
    }

    public function testMultipleQueuesAreAllListed(): void
    {
        $queue = new MonitorStubQueue(['high' => 2, 'default' => 5, 'low' => 0]);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle(['--queues=high,default,low']));

        $this->assertStringContainsString('high', $output);
        $this->assertStringContainsString('default', $output);
        $this->assertStringContainsString('low', $output);
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('5', $output);
    }

    public function testOutputShowsFailedCountWhenDriverSupportsIt(): void
    {
        $queue = new MonitorStubQueueWithFailed(['default' => 0], failedCount: 4);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle([]));

        $this->assertStringContainsString('Failed', $output);
        $this->assertStringContainsString('4', $output);
    }

    public function testOutputShowsNaForFailedWhenDriverDoesNotSupportIt(): void
    {
        $queue = new MonitorStubQueue(['default' => 0]);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle([]));

        $this->assertStringContainsString('n/a', strtolower($output));
    }

    public function testReturnsZeroExitCode(): void
    {
        $cmd = new MonitorCommand(new MonitorStubQueue());
        $code = 0;
        $this->capture(function () use ($cmd, &$code): void {
            $code = $cmd->handle([]);
        });

        $this->assertSame(0, $code);
    }

    public function testCustomQueuesOptionOverridesDefault(): void
    {
        $queue = new MonitorStubQueue(['emails' => 11, 'default' => 3]);
        $cmd = new MonitorCommand($queue);

        $output = $this->capture(fn () => $cmd->handle(['--queues=emails']));

        $this->assertStringContainsString('emails', $output);
        $this->assertStringContainsString('11', $output);
        // 'default' queue was NOT requested — must not appear as a separate row
        // (it may appear in the header, so we check the number 3 is absent)
        $this->assertStringNotContainsString("\n  default", $output);
    }
}
