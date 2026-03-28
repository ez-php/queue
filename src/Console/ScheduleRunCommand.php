<?php

declare(strict_types=1);

namespace EzPhp\Queue\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Scheduling\Scheduler;

/**
 * Class ScheduleRunCommand
 *
 * Checks the Scheduler for tasks that are due at the current minute and pushes
 * each due task's job onto the queue.
 *
 * Intended to be invoked by a system cron entry every minute:
 *   * * * * * php ez queue:schedule
 *
 * The application configures scheduled tasks in a service provider's boot():
 *   $scheduler->job(SendDailyReport::class)->daily();
 *   $scheduler->job(PruneExpiredTokens::class)->hourly();
 *
 * @package EzPhp\Queue\Console
 */
final readonly class ScheduleRunCommand implements CommandInterface
{
    /**
     * ScheduleRunCommand Constructor
     *
     * @param Scheduler      $scheduler
     * @param QueueInterface $queue
     */
    public function __construct(
        private Scheduler $scheduler,
        private QueueInterface $queue,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'queue:schedule';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Push scheduled jobs that are due now onto the queue';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return 'Usage: ez queue:schedule' . "\n" .
               'Invoke every minute via system cron: * * * * * php ez queue:schedule';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $now = new \DateTimeImmutable();
        $due = $this->scheduler->dueJobs($now);

        if ($due === []) {
            echo 'No scheduled jobs are due at ' . $now->format('Y-m-d H:i') . ".\n";

            return 0;
        }

        foreach ($due as $task) {
            $job = $task->createJob();
            $this->queue->push($job);
            echo 'Queued: ' . $task->getDescription() . "\n";
        }

        return 0;
    }
}
