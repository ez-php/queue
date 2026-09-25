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
 * Delayed jobs (`getDelay() > 0`, including jobs the Worker re-pushes after
 * `Job::releaseAfter()` or a retry) go to a sorted set (`queues:delayed:{name}`)
 * scored by the Unix time they become available. pop() first moves due members
 * onto the list in one atomic Lua script, so concurrent workers never move the
 * same job twice. Each delayed member carries a random `id` in its envelope —
 * sorted-set members are unique, so two byte-identical jobs would otherwise
 * collapse into one.
 *
 * Requires ext-redis. Throws QueueException at construction if the
 * extension is not loaded.
 *
 * @package EzPhp\Queue\Driver
 */
final class RedisDriver implements QueueInterface
{
    /**
     * Moves due members (score <= ARGV[1]) from the delayed set KEYS[1] onto the
     * ready list KEYS[2], at most ARGV[2] per call. Runs atomically in Redis.
     */
    private const string MIGRATE_DUE_SCRIPT = <<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
        for _, member in ipairs(due) do
            redis.call('RPUSH', KEYS[2], member)
            redis.call('ZREM', KEYS[1], member)
        end
        return #due
        LUA;

    /**
     * Upper bound on delayed jobs moved per pop(), so one call never blocks Redis for long.
     */
    private const int MIGRATE_BATCH = 100;

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
     * Jobs with delay > 0 are held in the delayed set until they are due.
     *
     * @param JobInterface $job
     *
     * @return void
     */
    public function push(JobInterface $job): void
    {
        $delay = $job->getDelay();

        try {
            // The envelope lists every class in the payload so pop() can restrict
            // allowed_classes to exactly those — see JobSerializer.
            $envelope = JobSerializer::serialize($job);

            if ($delay > 0) {
                // Sorted-set members are unique: without an id, two identical
                // delayed jobs would collapse into one. JobSerializer ignores it.
                $envelope['id'] = bin2hex(random_bytes(8));
            }

            $payload = json_encode($envelope, JSON_THROW_ON_ERROR);
        } catch (QueueException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new QueueException(
                'Job cannot be serialized: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($delay > 0) {
            $this->redis->zAdd('queues:delayed:' . $job->getQueue(), time() + $delay, $payload);

            return;
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
        $this->migrateDueJobs($queue);

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
     * Return the number of jobs available now in the given queue — ready jobs
     * plus delayed jobs that are already due. Jobs still waiting out their
     * delay are not counted, matching DatabaseDriver and InMemoryDriver.
     *
     * @param string $queue
     *
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        $ready = (int) $this->redis->lLen('queues:' . $queue);
        $due = (int) $this->redis->zCount('queues:delayed:' . $queue, '-inf', (string) time());

        return $ready + $due;
    }

    /**
     * Move delayed jobs whose time has come onto the ready list.
     *
     * @param string $queue
     *
     * @return void
     *
     * @throws QueueException When Redis rejects the migration script.
     */
    private function migrateDueJobs(string $queue): void
    {
        $moved = $this->redis->eval(
            self::MIGRATE_DUE_SCRIPT,
            ['queues:delayed:' . $queue, 'queues:' . $queue, (string) time(), (string) self::MIGRATE_BATCH],
            2,
        );

        if ($moved === false) {
            throw new QueueException('Failed to move due delayed jobs: ' . ($this->redis->getLastError() ?? 'unknown Redis error'));
        }
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
