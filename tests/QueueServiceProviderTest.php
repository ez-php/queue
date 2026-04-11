<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Queue\Console\FailedCommand;
use EzPhp\Queue\Console\ScheduleRunCommand;
use EzPhp\Queue\Console\WorkCommand;
use EzPhp\Queue\QueueServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class QueueServiceProviderTest
 *
 * Verifies that QueueServiceProvider::boot() auto-registers the queue console
 * commands with the Application so they are available in the CLI without
 * requiring manual $app->registerCommand() calls.
 *
 * @package Tests
 */
#[CoversClass(QueueServiceProvider::class)]
final class QueueServiceProviderTest extends TestCase
{
    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(QueueServiceProvider::class);
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_work_command(): void
    {
        $this->assertContains(WorkCommand::class, $this->app()->getCommands());
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_failed_command(): void
    {
        $this->assertContains(FailedCommand::class, $this->app()->getCommands());
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_schedule_run_command(): void
    {
        $this->assertContains(ScheduleRunCommand::class, $this->app()->getCommands());
    }
}
