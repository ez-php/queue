<?php

declare(strict_types=1);

namespace EzPhp\Queue\Scheduling;

use EzPhp\Contracts\JobInterface;

/**
 * Class Scheduler
 *
 * Central registry for recurring jobs. Application code adds tasks during the
 * service-provider boot phase; the ScheduleRunCommand queries which tasks are
 * due and pushes them onto the queue.
 *
 * Usage (in a ServiceProvider::boot()):
 *
 *   $scheduler = $app->make(Scheduler::class);
 *   $scheduler->job(SendDailyReport::class)->daily();
 *   $scheduler->job(PruneOldTokens::class)->hourly();
 *   $scheduler->job(SyncExternalData::class)->everyMinutes(15);
 *
 * Run from a system cron every minute:
 *   * * * * * php ez queue:schedule
 *
 * @package EzPhp\Queue\Scheduling
 */
final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    /**
     * Register a job class with the scheduler and return its ScheduledTask for
     * chaining the schedule expression.
     *
     * @param class-string<JobInterface> $jobClass
     *
     * @return ScheduledTask
     */
    public function job(string $jobClass): ScheduledTask
    {
        $task = new ScheduledTask($jobClass);
        $this->tasks[] = $task;

        return $task;
    }

    /**
     * Return all registered tasks.
     *
     * @return list<ScheduledTask>
     */
    public function all(): array
    {
        return $this->tasks;
    }

    /**
     * Return tasks whose schedule is due at the given moment.
     *
     * @param \DateTimeImmutable $now
     *
     * @return list<ScheduledTask>
     */
    public function dueJobs(\DateTimeImmutable $now): array
    {
        return array_values(
            array_filter($this->tasks, static fn (ScheduledTask $t): bool => $t->isDue($now)),
        );
    }
}
