<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Console\FailedCommand;
use EzPhp\Queue\FailedJobRepositoryInterface;
use EzPhp\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Stub FailedJobRepositoryInterface + QueueInterface for FailedCommand tests.
 */
final class FailedRepositoryStub implements FailedJobRepositoryInterface, QueueInterface
{
    /** @var list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}> */
    public array $records = [];

    /** @var list<JobInterface> */
    public array $pushed = [];

    public bool $flushed = false;

    /**
     * @return list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function retry(int $id, QueueInterface $queue): bool
    {
        return $this->removeById($id);
    }

    public function forget(int $id): bool
    {
        return $this->removeById($id);
    }

    private function removeById(int $id): bool
    {
        /** @var list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}> $kept */
        $kept = [];
        $found = false;

        foreach ($this->records as $record) {
            if ($record['id'] === $id && !$found) {
                $found = true;
                continue;
            }

            $kept[] = $record;
        }

        if ($found) {
            $this->records = $kept;
        }

        return $found;
    }

    public function flush(): void
    {
        $this->records = [];
        $this->flushed = true;
    }

    public function push(JobInterface $job): void
    {
        $this->pushed[] = $job;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
    }
}

/**
 * Class FailedCommandTest
 *
 * @package Tests\Console
 */
#[CoversClass(FailedCommand::class)]
#[UsesClass(Job::class)]
final class FailedCommandTest extends TestCase
{
    private FailedRepositoryStub $stub;

    private FailedCommand $command;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->stub = new FailedRepositoryStub();
        $this->command = new FailedCommand($this->stub, $this->stub);
    }

    // ─── getName / getDescription / getHelp ──────────────────────────────────

    /**
     * @return void
     */
    public function test_get_name(): void
    {
        $this->assertSame('queue:failed', $this->command->getName());
    }

    /**
     * @return void
     */
    public function test_get_description(): void
    {
        $this->assertStringContainsString('failed', $this->command->getDescription());
    }

    /**
     * @return void
     */
    public function test_get_help_contains_subcommands(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('list', $help);
        $this->assertStringContainsString('retry', $help);
        $this->assertStringContainsString('delete', $help);
        $this->assertStringContainsString('flush', $help);
    }

    // ─── list ─────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_list_shows_no_failed_jobs_message_when_empty(): void
    {
        ob_start();
        $exit = $this->command->handle(['list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No failed jobs', (string) $output);
    }

    /**
     * @return void
     */
    public function test_list_shows_failed_job_details(): void
    {
        $this->stub->records = [
            ['id' => 1, 'queue' => 'default', 'payload' => '', 'exception' => 'Connection refused', 'failed_at' => '2024-06-15 10:00:00'],
        ];

        ob_start();
        $exit = $this->command->handle(['list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[1]', (string) $output);
        $this->assertStringContainsString('default', (string) $output);
        $this->assertStringContainsString('Connection refused', (string) $output);
    }

    /**
     * @return void
     */
    public function test_list_is_default_subcommand(): void
    {
        ob_start();
        $exit = $this->command->handle([]);
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No failed jobs', (string) $output);
    }

    // ─── retry ────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_retry_with_valid_id_returns_0(): void
    {
        $this->stub->records = [
            ['id' => 42, 'queue' => 'default', 'payload' => '', 'exception' => 'err', 'failed_at' => '2024-01-01'],
        ];

        ob_start();
        $exit = $this->command->handle(['retry', '42']);
        ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertEmpty($this->stub->records);
    }

    /**
     * @return void
     */
    public function test_retry_with_missing_id_returns_1(): void
    {
        ob_start();
        $exit = $this->command->handle(['retry', '999']);
        ob_get_clean();

        $this->assertSame(1, $exit);
    }

    /**
     * @return void
     */
    public function test_retry_without_id_returns_error(): void
    {
        ob_start();
        $exit = $this->command->handle(['retry']);
        $output = ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Error', (string) $output);
    }

    // ─── delete ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_delete_with_valid_id_removes_record(): void
    {
        $this->stub->records = [
            ['id' => 7, 'queue' => 'default', 'payload' => '', 'exception' => 'err', 'failed_at' => '2024-01-01'],
        ];

        ob_start();
        $exit = $this->command->handle(['delete', '7']);
        ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertEmpty($this->stub->records);
    }

    /**
     * @return void
     */
    public function test_delete_with_missing_id_returns_1(): void
    {
        ob_start();
        $exit = $this->command->handle(['delete', '999']);
        ob_get_clean();

        $this->assertSame(1, $exit);
    }

    /**
     * @return void
     */
    public function test_delete_without_id_returns_error(): void
    {
        ob_start();
        $exit = $this->command->handle(['delete']);
        $output = ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Error', (string) $output);
    }

    // ─── flush ────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_flush_clears_all_records(): void
    {
        $this->stub->records = [
            ['id' => 1, 'queue' => 'default', 'payload' => '', 'exception' => 'e', 'failed_at' => '2024-01-01'],
            ['id' => 2, 'queue' => 'default', 'payload' => '', 'exception' => 'e', 'failed_at' => '2024-01-01'],
        ];

        ob_start();
        $exit = $this->command->handle(['flush']);
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertTrue($this->stub->flushed);
        $this->assertStringContainsString('deleted', (string) $output);
    }

    // ─── unknown subcommand ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_unknown_subcommand_returns_1(): void
    {
        ob_start();
        $exit = $this->command->handle(['bogus']);
        $output = ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown subcommand', (string) $output);
    }
}
