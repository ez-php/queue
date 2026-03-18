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

/**
 * Class QueueServiceProvider
 *
 * Binds QueueInterface to the driver selected by config/queue.php and
 * registers Worker with its QueueInterface dependency.
 *
 * Supported drivers: database (default), redis.
 *
 * To add the WorkCommand to the CLI, call:
 *   $app->registerCommand(\EzPhp\Queue\Console\WorkCommand::class)
 * before bootstrapping.
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
