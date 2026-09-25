<?php

declare(strict_types=1);

namespace EzPhp\Queue\Driver;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\JobSerializer;
use EzPhp\Queue\QueueException;
use Redis;

/**
 * Class RedisDriver
 *
 * Redis-backed queue driver using the PHP ext-redis extension.
 *
 * Jobs are pushed onto a Redis list (`queues:{name}`) with RPUSH and
 * consumed via LPOP. This gives FIFO order within a queue.
 *
 * Failed jobs are appended to a separate list (`queues:failed:{name}`).
 *
 * Delay constraint: Redis lists do not support native deferred delivery.
 * Jobs with a delay > 0 are pushed immediately — the delay property is
 * serialised with the job but not enforced by this driver. Use the
 * DatabaseDriver if delayed job delivery is required.
 *
 * Requires ext-redis. Throws QueueException at construction if the
 * extension is not loaded.
 *
 * @package EzPhp\Queue\Driver
 */
final class RedisDriver implements QueueInterface
{
    private Redis $redis;

    /**
     * RedisDriver Constructor
     *
     * @param string $host     Redis hostname.
     * @param int    $port     Redis port.
     * @param int    $database Redis database index.
     *
     * @throws QueueException When ext-redis is not loaded.
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $database = 0
    ) {
        if (!extension_loaded('redis')) {
            throw new QueueException('ext-redis is required to use RedisDriver.');
        }

        $this->redis = new Redis();

        try {
            $connected = @$this->redis->connect($host, $port);
        } catch (\RedisException $e) {
            throw new QueueException("Redis connection failed: {$e->getMessage()}", previous: $e);
        }

        if (!$connected) {
            throw new QueueException("Redis connection failed: could not connect to {$host}:{$port}.");
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }
    }

    /**
     * Push a job onto the queue.
     *
     * Jobs with delay > 0 are queued immediately — the Redis driver does not
     * enforce delayed delivery. Use DatabaseDriver for delayed jobs.
     *
     * @param JobInterface $job
     *
     * @return void
     */
    public function push(JobInterface $job): void
    {
        try {
            // The envelope lists every class in the payload so pop() can restrict
            // allowed_classes to exactly those — see JobSerializer.
            $payload = json_encode(JobSerializer::serialize($job), JSON_THROW_ON_ERROR);
        } catch (QueueException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new QueueException(
                'Job cannot be serialized: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $this->redis->rPush('queues:' . $job->getQueue(), $payload);
    }

    /**
     * Pop the next available job from the given queue.
     *
     * Returns null when the queue is empty.
     *
     * @param string $queue
     *
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $payload = $this->redis->lPop('queues:' . $queue);

        if ($payload === false) {
            return null;
        }

        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($envelope)) {
            throw new QueueException('Invalid job payload envelope.');
        }

        // allowed_classes is restricted to the classes recorded at push() time.
        // Trust model: the queue list is only ever written by push() — see JobSerializer.
        return JobSerializer::unserialize($envelope);
    }

    /**
     * Return the number of pending jobs in the given queue.
     *
     * @param string $queue
     *
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->lLen('queues:' . $queue);
    }

    /**
     * Append the failed job to the `queues:failed:{queue}` list.
     *
     * @param JobInterface $job
     * @param \Throwable   $exception
     *
     * @return void
     */
    public function failed(JobInterface $job, \Throwable $exception): void
    {
        $envelope = JobSerializer::serialize($job);
        $payload = json_encode([
            'class' => $envelope['class'],
            'classes' => $envelope['classes'],
            'job' => $envelope['data'],
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'failed_at' => date('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR);

        $this->redis->rPush('queues:failed:' . $job->getQueue(), $payload);
    }
}
