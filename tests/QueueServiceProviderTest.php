<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\CommandRegistryInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Queue\Console\FailedCommand;
use EzPhp\Queue\Console\ScheduleRunCommand;
use EzPhp\Queue\Console\WorkCommand;
use EzPhp\Queue\QueueServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Class QueueServiceProviderTest
 *
 * Verifies that QueueServiceProvider::boot() auto-registers the queue console
 * commands when the container implements CommandRegistryInterface.
 *
 * Uses a fake container instead of a real Application so the test is
 * independent of which version of ez-php/framework is installed.
 *
 * @package Tests
 */
#[CoversClass(QueueServiceProvider::class)]
final class QueueServiceProviderTest extends BaseTestCase
{
    /**
     * @return ContainerInterface&CommandRegistryInterface
     */
    private function makeRegistry(): ContainerInterface&CommandRegistryInterface
    {
        return new class () implements ContainerInterface, CommandRegistryInterface {
            /** @var list<class-string> */
            private array $commands = [];

            public function registerCommand(string $commandClass): static
            {
                $this->commands[] = $commandClass;

                return $this;
            }

            /**
             * @return list<class-string>
             */
            public function getCommands(): array
            {
                return $this->commands;
            }

            public function bind(string $abstract, string|callable|null $factory = null): static
            {
                return $this;
            }

            public function make(string $abstract): mixed
            {
                throw new \RuntimeException('not implemented in test stub');
            }

            public function instance(string $abstract, object $instance): void
            {
            }
        };
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_work_command(): void
    {
        $registry = $this->makeRegistry();
        (new QueueServiceProvider($registry))->boot();

        $this->assertContains(WorkCommand::class, $registry->getCommands());
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_failed_command(): void
    {
        $registry = $this->makeRegistry();
        (new QueueServiceProvider($registry))->boot();

        $this->assertContains(FailedCommand::class, $registry->getCommands());
    }

    /**
     * @return void
     */
    public function test_boot_auto_registers_schedule_run_command(): void
    {
        $registry = $this->makeRegistry();
        (new QueueServiceProvider($registry))->boot();

        $this->assertContains(ScheduleRunCommand::class, $registry->getCommands());
    }

    /**
     * @return void
     */
    public function test_boot_does_nothing_without_command_registry(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new class () implements ContainerInterface {
            public function bind(string $abstract, string|callable|null $factory = null): static
            {
                return $this;
            }

            public function make(string $abstract): mixed
            {
                throw new \RuntimeException('not implemented');
            }

            public function instance(string $abstract, object $instance): void
            {
            }
        };

        (new QueueServiceProvider($container))->boot();
    }
}
