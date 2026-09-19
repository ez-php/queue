<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\Job;
use EzPhp\Queue\Lock\InMemoryJobLock;
use EzPhp\Queue\Middleware\JobMiddlewareInterface;
use EzPhp\Queue\Middleware\RateLimited;
use EzPhp\Queue\Middleware\WithoutOverlapping;
use EzPhp\RateLimiter\ArrayDriver;

/**
 * Shared static state for the middleware fixtures. Jobs are serialized by the
 * drivers, so instance state would be lost between push and pop.
 */
final class QueueMwState
{
    /** @var list<string> */
    public static array $log = [];

    public static bool $throwInHandle = false;

    public static ?InMemoryJobLock $locks = null;

    public static ?ArrayDriver $limiter = null;

    public static function reset(): void
    {
        self::$log = [];
        self::$throwInHandle = false;
        self::$locks = new InMemoryJobLock();
        self::$limiter = new ArrayDriver();
    }
}

final class QueueMwLoggingMiddleware implements JobMiddlewareInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function handle(JobInterface $job, callable $next): void
    {
        QueueMwState::$log[] = $this->name . ':before';
        $next($job);
        QueueMwState::$log[] = $this->name . ':after';
    }
}

final class QueueMwThrowingMiddleware implements JobMiddlewareInterface
{
    public function handle(JobInterface $job, callable $next): void
    {
        throw new \RuntimeException('middleware failed');
    }
}

final class QueueMwReleasingMiddleware implements JobMiddlewareInterface
{
    public function handle(JobInterface $job, callable $next): void
    {
        if ($job instanceof Job) {
            $job->releaseAfter(30);
        }
    }
}

final class QueueMwPipelineJob extends Job
{
    public function handle(): void
    {
        QueueMwState::$log[] = 'handle';

        if (QueueMwState::$throwInHandle) {
            throw new \RuntimeException('handle failed');
        }
    }

    public function middleware(): array
    {
        return [new QueueMwLoggingMiddleware('A'), new QueueMwLoggingMiddleware('B')];
    }
}

final class QueueMwThrowingMiddlewareJob extends Job
{
    public function handle(): void
    {
        QueueMwState::$log[] = 'handle';
    }

    public function middleware(): array
    {
        return [new QueueMwThrowingMiddleware()];
    }
}

final class QueueMwReleasedJob extends Job
{
    public function handle(): void
    {
        QueueMwState::$log[] = 'handle';
    }

    public function middleware(): array
    {
        return [new QueueMwReleasingMiddleware()];
    }
}

final class QueueMwNoOverlapJob extends Job
{
    public function handle(): void
    {
        QueueMwState::$log[] = 'handle';

        if (QueueMwState::$throwInHandle) {
            throw new \RuntimeException('handle failed');
        }
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping(self::locks(), 'reports', releaseAfter: 15, expireAfter: 60)];
    }

    public static function locks(): InMemoryJobLock
    {
        return QueueMwState::$locks ?? new InMemoryJobLock();
    }
}

final class QueueMwRateLimitedJob extends Job
{
    public function handle(): void
    {
        QueueMwState::$log[] = 'handle';
    }

    public function middleware(): array
    {
        return [new RateLimited(QueueMwState::$limiter ?? new ArrayDriver(), 'mail', 2, 60)];
    }
}

/**
 * Queue that records pushes instead of storing them, so delayed jobs stay observable.
 */
final class QueueMwCapturingQueue implements QueueInterface
{
    /** @var list<JobInterface> */
    public array $pushed = [];

    /** @var list<JobInterface> */
    private array $seeded = [];

    public function seed(JobInterface $job): void
    {
        $this->seeded[] = $job;
    }

    public function push(JobInterface $job): void
    {
        $this->pushed[] = $job;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        return array_shift($this->seeded);
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->seeded);
    }

    public function failed(JobInterface $job, \Throwable $exception): void
    {
    }
}
