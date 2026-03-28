<?php

declare(strict_types=1);

namespace Tests\Scheduling;

use EzPhp\Queue\Job;
use EzPhp\Queue\Scheduling\ScheduledTask;
use EzPhp\Queue\Scheduling\Scheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * A second dummy job to test multiple registrations.
 */
final class AnotherScheduledJob extends Job
{
    public function handle(): void
    {
    }
}

/**
 * Class SchedulerTest
 *
 * @package Tests\Scheduling
 */
#[CoversClass(Scheduler::class)]
#[UsesClass(ScheduledTask::class)]
#[UsesClass(Job::class)]
final class SchedulerTest extends TestCase
{
    /**
     * @return void
     */
    public function test_starts_empty(): void
    {
        $scheduler = new Scheduler();

        $this->assertSame([], $scheduler->all());
    }

    /**
     * @return void
     */
    public function test_job_registers_task_and_returns_it(): void
    {
        $scheduler = new Scheduler();
        $task = $scheduler->job(DummyScheduledJob::class);

        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertCount(1, $scheduler->all());
        $this->assertSame($task, $scheduler->all()[0]);
    }

    /**
     * @return void
     */
    public function test_multiple_jobs_are_all_registered(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(DummyScheduledJob::class);
        $scheduler->job(AnotherScheduledJob::class);

        $this->assertCount(2, $scheduler->all());
    }

    /**
     * @return void
     */
    public function test_due_jobs_returns_only_tasks_that_are_due(): void
    {
        $scheduler = new Scheduler();

        // Midnight-only task
        $scheduler->job(DummyScheduledJob::class)->daily();
        // Every-minute task
        $scheduler->job(AnotherScheduledJob::class)->everyMinute();

        // At 14:37 — only the every-minute task is due
        $now = new \DateTimeImmutable('2024-06-15 14:37:00');
        $due = $scheduler->dueJobs($now);

        $this->assertCount(1, $due);
        $this->assertSame(AnotherScheduledJob::class, $due[0]->getJobClass());
    }

    /**
     * @return void
     */
    public function test_due_jobs_returns_empty_when_nothing_is_due(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(DummyScheduledJob::class)->daily();

        // At 14:37 — daily task is not due
        $now = new \DateTimeImmutable('2024-06-15 14:37:00');

        $this->assertSame([], $scheduler->dueJobs($now));
    }

    /**
     * @return void
     */
    public function test_due_jobs_returns_all_due_tasks(): void
    {
        $scheduler = new Scheduler();
        $scheduler->job(DummyScheduledJob::class)->daily();
        $scheduler->job(AnotherScheduledJob::class)->daily();

        // Both are due at midnight
        $midnight = new \DateTimeImmutable('2024-06-15 00:00:00');
        $due = $scheduler->dueJobs($midnight);

        $this->assertCount(2, $due);
    }

    /**
     * @return void
     */
    public function test_returned_task_is_fluently_chainable(): void
    {
        $scheduler = new Scheduler();
        $task = $scheduler->job(DummyScheduledJob::class)
            ->daily()
            ->description('Send report');

        $this->assertSame('Send report', $task->getDescription());
    }
}
