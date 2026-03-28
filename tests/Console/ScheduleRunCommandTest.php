<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Console\ScheduleRunCommand;
use EzPhp\Queue\Job;
use EzPhp\Queue\Scheduling\ScheduledTask;
use EzPhp\Queue\Scheduling\Scheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * A simple job for schedule-run tests.
 */
final class SchedulableJob extends Job
{
    public function handle(): void
    {
    }
}

/**
 * Minimal queue stub for ScheduleRunCommand tests.
 */
final class ScheduleQueueStub implements QueueInterface
{
    /** @var list<JobInterface> */
    public array $pushed = [];

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
 * Class ScheduleRunCommandTest
 *
 * @package Tests\Console
 */
#[CoversClass(ScheduleRunCommand::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(ScheduledTask::class)]
#[UsesClass(Job::class)]
final class ScheduleRunCommandTest extends TestCase
{
    /**
     * @return void
     */
    public function test_get_name(): void
    {
        $cmd = new ScheduleRunCommand(new Scheduler(), new ScheduleQueueStub());

        $this->assertSame('queue:schedule', $cmd->getName());
    }

    /**
     * @return void
     */
    public function test_get_description(): void
    {
        $cmd = new ScheduleRunCommand(new Scheduler(), new ScheduleQueueStub());

        $this->assertStringContainsString('schedule', $cmd->getDescription());
    }

    /**
     * @return void
     */
    public function test_get_help_contains_cron_hint(): void
    {
        $cmd = new ScheduleRunCommand(new Scheduler(), new ScheduleQueueStub());

        $this->assertStringContainsString('queue:schedule', $cmd->getHelp());
    }

    /**
     * @return void
     */
    public function test_handle_pushes_due_jobs(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(SchedulableJob::class)->everyMinute();

        $queue = new ScheduleQueueStub();
        $cmd = new ScheduleRunCommand($scheduler, $queue);

        ob_start();
        $exit = $cmd->handle([]);
        ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertCount(1, $queue->pushed);
        $this->assertInstanceOf(SchedulableJob::class, $queue->pushed[0]);
    }

    /**
     * @return void
     */
    public function test_handle_does_not_push_non_due_jobs(): void
    {
        $scheduler = new Scheduler();
        // daily() is only due at 00:00 — won't be due during tests
        $scheduler->job(SchedulableJob::class)->daily();

        $queue = new ScheduleQueueStub();
        $cmd = new ScheduleRunCommand($scheduler, $queue);

        // We can't easily mock DateTimeImmutable inside the command, so we
        // register a task that is guaranteed NOT due at any test-run time.
        // The only reliable way: use cron '59 23 31 12 *' (very specific date/time)
        $scheduler2 = new Scheduler();
        $scheduler2->job(SchedulableJob::class)->cron('59 23 31 12 *');

        $queue2 = new ScheduleQueueStub();
        $cmd2 = new ScheduleRunCommand($scheduler2, $queue2);

        ob_start();
        $cmd2->handle([]);
        ob_get_clean();

        // If the test happens to run on Dec 31 at 23:59 this would fail, but that's acceptable
        $this->assertCount(0, $queue2->pushed);
    }

    /**
     * @return void
     */
    public function test_handle_shows_no_due_message_when_nothing_due(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(SchedulableJob::class)->cron('59 23 31 12 *');

        $queue = new ScheduleQueueStub();
        $cmd = new ScheduleRunCommand($scheduler, $queue);

        ob_start();
        $exit = $cmd->handle([]);
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No scheduled jobs', (string) $output);
    }

    /**
     * @return void
     */
    public function test_handle_pushes_multiple_due_jobs(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(SchedulableJob::class)->everyMinute();
        $scheduler->job(SchedulableJob::class)->everyMinute();

        $queue = new ScheduleQueueStub();
        $cmd = new ScheduleRunCommand($scheduler, $queue);

        ob_start();
        $cmd->handle([]);
        ob_get_clean();

        $this->assertCount(2, $queue->pushed);
    }
}
