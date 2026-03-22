<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\Driver\RedisDriver;
use EzPhp\Queue\Scheduling\Scheduler;

/**
 * Class QueueServiceProvider
 *
 * Binds QueueInterface to the driver selected by config/queue.php and
 * registers Worker, Scheduler, and FailedJobRepositoryInterface (when the
 * database driver is active).
 *
 * Supported drivers: database (default), redis.
 *
 * To add queue commands to the CLI, call the relevant registerCommand() before
 * bootstrapping:
 *   $app->registerCommand(\EzPhp\Queue\Console\WorkCommand::class)
 *   $app->registerCommand(\EzPhp\Queue\Console\FailedCommand::class)
 *   $app->registerCommand(\EzPhp\Queue\Console\ScheduleRunCommand::class)
 *
 * @package EzPhp\Queue
 */
final class QueueServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(QueueInterface::class, function (ContainerInterface $app): QueueInterface {
            $config = $app->make(ConfigInterface::class);
            $driver = $config->get('queue.driver', 'database');
            $driver = is_string($driver) ? $driver : 'database';

            return match ($driver) {
                'redis' => $this->makeRedis($config),
                default => $this->makeDatabase($app),
            };
        });

        $this->app->bind(Worker::class, function (ContainerInterface $app): Worker {
            return new Worker($app->make(QueueInterface::class));
        });

        $this->app->bind(Scheduler::class, function (): Scheduler {
            return new Scheduler();
        });

        // FailedJobRepositoryInterface is only available with the database driver.
        // The binding is registered unconditionally; resolving it when using
        // the Redis driver will throw a ContainerException.
        $this->app->bind(
            FailedJobRepositoryInterface::class,
            function (ContainerInterface $app): FailedJobRepositoryInterface {
                $queue = $app->make(QueueInterface::class);

                if (!$queue instanceof FailedJobRepositoryInterface) {
                    throw new QueueException(
                        'The active queue driver does not implement FailedJobRepositoryInterface. ' .
                        'Switch to the database driver to use queue:failed.'
                    );
                }

                return $queue;
            }
        );
    }

    /**
     * @param ContainerInterface $app
     *
     * @return DatabaseDriver
     */
    private function makeDatabase(ContainerInterface $app): DatabaseDriver
    {
        $db = $app->make(DatabaseInterface::class);

        return new DatabaseDriver($db->getPdo());
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisDriver
     */
    private function makeRedis(ConfigInterface $config): RedisDriver
    {
        $host = $config->get('queue.redis.host', '127.0.0.1');
        $port = $config->get('queue.redis.port', 6379);
        $db = $config->get('queue.redis.database', 0);

        return new RedisDriver(
            is_string($host) ? $host : '127.0.0.1',
            is_int($port) ? $port : 6379,
            is_int($db) ? $db : 0,
        );
    }
}
