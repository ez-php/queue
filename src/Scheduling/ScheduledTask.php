<?php

declare(strict_types=1);

namespace EzPhp\Queue\Scheduling;

use EzPhp\Contracts\JobInterface;

/**
 * Class ScheduledTask
 *
 * Represents a single recurring job in the scheduler. Combines a job class
 * with a schedule expression that determines when it is due to run.
 *
 * Supported schedule helpers:
 *   everyMinute()         — runs every minute       (* * * * *)
 *   everyMinutes(n)       — runs every N minutes     (*\/n * * * *)
 *   hourly()              — runs at :00 each hour    (0 * * * *)
 *   hourlyAt(m)           — runs at :m each hour     (m * * * *)
 *   daily()               — runs at midnight         (0 0 * * *)
 *   dailyAt('H:M')        — runs at specific time    (M H * * *)
 *   weekly()              — runs on Sunday midnight  (0 0 * * 0)
 *   weeklyOn(dow, 'H:M')  — runs on given weekday
 *   cron('* * * * *')     — explicit cron expression
 *
 * The five cron fields are (in order): minute hour day-of-month month day-of-week.
 * Supported field syntax: *, N, *\/N.
 *
 * @package EzPhp\Queue\Scheduling
 */
final class ScheduledTask
{
    private string $cronExpression = '* * * * *';

    private string $description = '';

    /**
     * ScheduledTask Constructor
     *
     * @param class-string<JobInterface> $jobClass Fully-qualified class name of the job to schedule.
     */
    public function __construct(private readonly string $jobClass)
    {
    }

    /**
     * Set an explicit five-field cron expression.
     *
     * @param string $expression Cron expression: "minute hour dom month dow".
     *
     * @return $this
     */
    public function cron(string $expression): self
    {
        $this->cronExpression = $expression;

        return $this;
    }

    /**
     * Run the job every minute.
     *
     * @return $this
     */
    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    /**
     * Run the job every N minutes.
     *
     * @param int $n Interval in minutes (must be >= 1).
     *
     * @return $this
     */
    public function everyMinutes(int $n): self
    {
        return $this->cron("*/{$n} * * * *");
    }

    /**
     * Run the job at the start of every hour (:00).
     *
     * @return $this
     */
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    /**
     * Run the job at minute $minute of every hour.
     *
     * @param int $minute 0–59
     *
     * @return $this
     */
    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    /**
     * Run the job once per day at midnight.
     *
     * @return $this
     */
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Run the job once per day at the given time.
     *
     * @param string $time Time in 'H:M' or 'H:MM' format (e.g. '8:30', '14:00').
     *
     * @return $this
     */
    public function dailyAt(string $time): self
    {
        $parts = explode(':', $time, 2);
        $hour = (int) $parts[0];
        $minute = isset($parts[1]) ? (int) $parts[1] : 0;

        return $this->cron("{$minute} {$hour} * * *");
    }

    /**
     * Run the job once per week on Sunday at midnight.
     *
     * @return $this
     */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * Run the job once per week on the given day of the week.
     *
     * @param int    $weekday 0 (Sunday) through 6 (Saturday)
     * @param string $time    Time in 'H:M' format (default: midnight)
     *
     * @return $this
     */
    public function weeklyOn(int $weekday, string $time = '0:00'): self
    {
        $parts = explode(':', $time, 2);
        $hour = (int) $parts[0];
        $minute = isset($parts[1]) ? (int) $parts[1] : 0;

        return $this->cron("{$minute} {$hour} * * {$weekday}");
    }

    /**
     * Set a human-readable description for this task.
     *
     * @param string $description
     *
     * @return $this
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Check whether this task is due to run at the given moment.
     *
     * Compares the cron expression against the minute, hour, day-of-month,
     * month, and day-of-week values of $now.
     *
     * @param \DateTimeImmutable $now
     *
     * @return bool
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $parts = explode(' ', $this->cronExpression);

        if (count($parts) !== 5) {
            return false;
        }

        return $this->matchField($parts[0], (int) $now->format('i'))
            && $this->matchField($parts[1], (int) $now->format('G'))
            && $this->matchField($parts[2], (int) $now->format('j'))
            && $this->matchField($parts[3], (int) $now->format('n'))
            && $this->matchField($parts[4], (int) $now->format('w'));
    }

    /**
     * Instantiate and return a new job instance for this task.
     *
     * @return JobInterface
     */
    public function createJob(): JobInterface
    {
        return new $this->jobClass();
    }

    /**
     * Returns the job class name.
     *
     * @return class-string<JobInterface>
     */
    public function getJobClass(): string
    {
        return $this->jobClass;
    }

    /**
     * Returns the description, falling back to the job class name.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description !== '' ? $this->description : $this->jobClass;
    }

    /**
     * Returns the current cron expression.
     *
     * @return string
     */
    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    /**
     * Match a single cron field against the current value.
     *
     * Supported patterns: * (any), N (exact), *\/N (every N steps from 0).
     *
     * @param string $field Cron field value.
     * @param int    $value Current calendar value.
     *
     * @return bool
     */
    private function matchField(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }

        if (str_starts_with($field, '*/')) {
            $n = (int) substr($field, 2);

            return $n > 0 && $value % $n === 0;
        }

        return (int) $field === $value;
    }
}
