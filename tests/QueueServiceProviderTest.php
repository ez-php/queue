<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\CommandRegistryInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Console\FailedCommand;
use EzPhp\Queue\Console\MonitorCommand;
use EzPhp\Queue\Console\ScheduleRunCommand;
use EzPhp\Queue\Console\WorkCommand;
use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Driver\RedisDriver;
use EzPhp\Queue\FailedJobRepositoryInterface;
use EzPhp\Queue\QueueException;
use EzPhp\Queue\QueueServiceProvider;
use EzPhp\Queue\Scheduling\Scheduler;
use EzPhp\Queue\Worker;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
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
#[UsesClass(DatabaseDriver::class)]
#[UsesClass(InMemoryDriver::class)]
#[UsesClass(RedisDriver::class)]
#[UsesClass(Worker::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(QueueException::class)]
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

            public function has(string $abstract): bool
            {
                return false;
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
    public function test_boot_auto_registers_monitor_command(): void
    {
        $registry = $this->makeRegistry();
        (new QueueServiceProvider($registry))->boot();

        $this->assertContains(MonitorCommand::class, $registry->getCommands());
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

            public function has(string $abstract): bool
            {
                return false;
            }

            public function instance(string $abstract, object $instance): void
            {
            }
        };

        (new QueueServiceProvider($container))->boot();
    }

    // ─── register(): driver resolution ────────────────────────────────────────

    /**
     * A minimal container holding the given config and an SQLite-backed
     * DatabaseInterface, with the provider's bindings registered.
     *
     * @param array<string, mixed> $config
     *
     * @return ContainerInterface
     */
    private function registered(array $config): ContainerInterface
    {
        $configInstance = new class ($config) implements ConfigInterface {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }
        };

        $db = new class () implements DatabaseInterface {
            private PDO $pdo;

            public function __construct()
            {
                $this->pdo = new PDO('sqlite::memory:');
            }

            public function query(string $sql, array $bindings = []): array
            {
                return [];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                return 0;
            }

            public function transaction(callable $fn): mixed
            {
                return $fn();
            }

            public function getPdo(): PDO
            {
                return $this->pdo;
            }
        };

        $container = new class ($configInstance, $db) implements ContainerInterface {
            /** @var array<string, callable> */
            private array $bindings = [];

            /** @var array<string, object> */
            private array $instances;

            public function __construct(ConfigInterface $config, DatabaseInterface $db)
            {
                $this->instances = [ConfigInterface::class => $config, DatabaseInterface::class => $db];
            }

            public function bind(string $abstract, string|callable|null $factory = null): static
            {
                if (is_callable($factory)) {
                    $this->bindings[$abstract] = $factory;
                }

                return $this;
            }

            public function make(string $abstract): mixed
            {
                return $this->instances[$abstract] ??= ($this->bindings[$abstract])($this);
            }

            public function has(string $abstract): bool
            {
                return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->instances[$abstract] = $instance;
            }
        };

        (new QueueServiceProvider($container))->register();

        return $container;
    }

    /**
     * @return array<string, array{array<string, mixed>, class-string<QueueInterface>}>
     */
    public static function drivers(): array
    {
        return [
            'default is database' => [[], DatabaseDriver::class],
            'database' => [['queue.driver' => 'database'], DatabaseDriver::class],
            'memory' => [['queue.driver' => 'memory'], InMemoryDriver::class],
            'unknown falls back to database' => [['queue.driver' => 'sqs'], DatabaseDriver::class],
            'non-string falls back to database' => [['queue.driver' => ['redis']], DatabaseDriver::class],
        ];
    }

    /**
     * @param array<string, mixed>         $config
     * @param class-string<QueueInterface> $expected
     *
     * @return void
     */
    #[DataProvider('drivers')]
    public function test_configured_driver_resolves_to_its_class(array $config, string $expected): void
    {
        $this->assertInstanceOf($expected, $this->registered($config)->make(QueueInterface::class));
    }

    /**
     * @return void
     */
    public function test_redis_driver_is_built_from_config(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available.');
        }

        $container = $this->registered([
            'queue.driver' => 'redis',
            'queue.redis.host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            'queue.redis.port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'queue.redis.database' => 1,
        ]);

        try {
            $queue = $container->make(QueueInterface::class);
        } catch (QueueException $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }

        $this->assertInstanceOf(RedisDriver::class, $queue);
    }

    /**
     * @return void
     */
    public function test_worker_and_scheduler_are_bound(): void
    {
        $container = $this->registered(['queue.driver' => 'memory']);

        $this->assertInstanceOf(Worker::class, $container->make(Worker::class));
        $this->assertInstanceOf(Scheduler::class, $container->make(Scheduler::class));
    }

    /**
     * @return void
     */
    public function test_failed_job_repository_is_the_database_driver(): void
    {
        $container = $this->registered(['queue.driver' => 'database']);

        $this->assertSame(
            $container->make(QueueInterface::class),
            $container->make(FailedJobRepositoryInterface::class),
        );
    }

    /**
     * @return void
     */
    public function test_failed_job_repository_throws_for_drivers_without_one(): void
    {
        $container = $this->registered(['queue.driver' => 'memory']);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('does not implement FailedJobRepositoryInterface');

        $container->make(FailedJobRepositoryInterface::class);
    }
}
