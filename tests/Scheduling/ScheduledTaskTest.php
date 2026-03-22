<?php

declare(strict_types=1);

namespace Tests\Scheduling;

use EzPhp\Contracts\JobInterface;
use EzPhp\Queue\Job;
use EzPhp\Queue\Scheduling\ScheduledTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * A minimal job used to test ScheduledTask::createJob().
 */
final class DummyScheduledJob extends Job
{
    public function handle(): void
    {
    }
}

/**
 * Class ScheduledTaskTest
 *
 * @package Tests\Scheduling
 */
#[CoversClass(ScheduledTask::class)]
#[UsesClass(Job::class)]
final class ScheduledTaskTest extends TestCase
{
    // ─── schedule helpers map to correct cron expressions ────────────────────

    /**
     * @return void
     */
    public function test_every_minute_sets_wildcard_expression(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->everyMinute();

        $this->assertSame('* * * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_every_minutes_sets_step_expression(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->everyMinutes(15);

        $this->assertSame('*/15 * * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_hourly_sets_correct_expression(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->hourly();

        $this->assertSame('0 * * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_hourly_at_sets_minute(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->hourlyAt(30);

        $this->assertSame('30 * * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_daily_sets_midnight_expression(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->daily();

        $this->assertSame('0 0 * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_daily_at_sets_specific_time(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->dailyAt('8:30');

        $this->assertSame('30 8 * * *', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_weekly_sets_sunday_midnight(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->weekly();

        $this->assertSame('0 0 * * 0', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_weekly_on_sets_weekday_and_time(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->weeklyOn(3, '9:00'); // Wednesday 09:00

        $this->assertSame('0 9 * * 3', $task->getCronExpression());
    }

    /**
     * @return void
     */
    public function test_cron_sets_arbitrary_expression(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $task->cron('5 4 * * 1');

        $this->assertSame('5 4 * * 1', $task->getCronExpression());
    }

    // ─── isDue matching ───────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_every_minute_is_always_due(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->everyMinute();

        $now = new \DateTimeImmutable('2024-06-15 14:37:00');
        $this->assertTrue($task->isDue($now));
    }

    /**
     * @return void
     */
    public function test_daily_is_due_at_midnight(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->daily();

        $midnight = new \DateTimeImmutable('2024-06-15 00:00:00');
        $this->assertTrue($task->isDue($midnight));
    }

    /**
     * @return void
     */
    public function test_daily_is_not_due_at_other_times(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->daily();

        $afternoon = new \DateTimeImmutable('2024-06-15 14:00:00');
        $this->assertFalse($task->isDue($afternoon));
    }

    /**
     * @return void
     */
    public function test_hourly_is_due_on_the_hour(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->hourly();

        $onTheHour = new \DateTimeImmutable('2024-06-15 14:00:00');
        $this->assertTrue($task->isDue($onTheHour));
    }

    /**
     * @return void
     */
    public function test_hourly_is_not_due_off_the_hour(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->hourly();

        $offHour = new \DateTimeImmutable('2024-06-15 14:30:00');
        $this->assertFalse($task->isDue($offHour));
    }

    /**
     * @return void
     */
    public function test_every_fifteen_minutes_is_due_at_0_15_30_45(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->everyMinutes(15);

        $this->assertTrue($task->isDue(new \DateTimeImmutable('2024-06-15 14:00:00')));
        $this->assertTrue($task->isDue(new \DateTimeImmutable('2024-06-15 14:15:00')));
        $this->assertTrue($task->isDue(new \DateTimeImmutable('2024-06-15 14:30:00')));
        $this->assertTrue($task->isDue(new \DateTimeImmutable('2024-06-15 14:45:00')));
    }

    /**
     * @return void
     */
    public function test_every_fifteen_minutes_not_due_at_other_minutes(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->everyMinutes(15);

        $this->assertFalse($task->isDue(new \DateTimeImmutable('2024-06-15 14:07:00')));
        $this->assertFalse($task->isDue(new \DateTimeImmutable('2024-06-15 14:31:00')));
    }

    /**
     * @return void
     */
    public function test_weekly_is_due_on_sunday_midnight(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->weekly();

        // 2024-06-16 is a Sunday
        $sunday = new \DateTimeImmutable('2024-06-16 00:00:00');
        $this->assertTrue($task->isDue($sunday));
    }

    /**
     * @return void
     */
    public function test_weekly_not_due_on_other_days(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->weekly();

        // 2024-06-15 is a Saturday (dow=6)
        $saturday = new \DateTimeImmutable('2024-06-15 00:00:00');
        $this->assertFalse($task->isDue($saturday));
    }

    /**
     * @return void
     */
    public function test_invalid_cron_expression_returns_false(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->cron('not valid');

        $this->assertFalse($task->isDue(new \DateTimeImmutable()));
    }

    // ─── metadata ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_job_class_returns_class_name(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);

        $this->assertSame(DummyScheduledJob::class, $task->getJobClass());
    }

    /**
     * @return void
     */
    public function test_get_description_defaults_to_class_name(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);

        $this->assertSame(DummyScheduledJob::class, $task->getDescription());
    }

    /**
     * @return void
     */
    public function test_description_sets_custom_label(): void
    {
        $task = (new ScheduledTask(DummyScheduledJob::class))->description('Send daily report');

        $this->assertSame('Send daily report', $task->getDescription());
    }

    // ─── createJob() ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_create_job_returns_instance_of_job_class(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);
        $job = $task->createJob();

        $this->assertInstanceOf(DummyScheduledJob::class, $job);
        $this->assertInstanceOf(JobInterface::class, $job);
    }

    /**
     * @return void
     */
    public function test_create_job_returns_new_instance_each_call(): void
    {
        $task = new ScheduledTask(DummyScheduledJob::class);

        $this->assertNotSame($task->createJob(), $task->createJob());
    }
}
