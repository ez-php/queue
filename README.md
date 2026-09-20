# ez-php/queue

Async job queue for the [ez-php framework](https://github.com/ez-php) — database and Redis drivers, a Worker, and a `queue:work` console command.

## Requirements

- PHP 8.5+
- ext-pdo
- ez-php/contracts 0.*
- ez-php/console 0.*
- ext-redis (Redis driver only)

## Installation

```bash
composer require ez-php/queue
```

## Setup

Register the service provider:

```php
$app->register(\EzPhp\Queue\QueueServiceProvider::class);
```

`WorkCommand` (`queue:work`), `FailedCommand` (`queue:failed`), and `ScheduleRunCommand`
(`queue:schedule`) are registered automatically by `QueueServiceProvider::boot()` when the
application implements `CommandRegistryInterface` — no manual `$app->registerCommand()` call
needed.

Add `config/queue.php` to your application:

```php
return [
    'driver' => getenv('QUEUE_DRIVER') ?: 'database',  // 'database' | 'redis'
    'redis'  => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
    ],
];
```

## Defining Jobs

```php
use EzPhp\Queue\Job;

final class SendWelcomeEmail extends Job
{
    protected string $queue    = 'emails';
    protected int    $maxTries = 5;

    public function __construct(private readonly string $email) {}

    public function handle(): void
    {
        // send the email ...
    }

    public function fail(\Throwable $exception): void
    {
        // log or notify on permanent failure
    }
}
```

## Dispatching Jobs

```php
use EzPhp\Contracts\QueueInterface;

$queue = $app->make(QueueInterface::class);
$queue->push(new SendWelcomeEmail('alice@example.com'));
```

Delay a job by setting `$delay` (seconds):

```php
final class ProcessReport extends Job
{
    protected int $delay = 60; // available after 60 seconds
    // ...
}
```

> **Note:** The Redis driver does not enforce `$delay`. Use the database driver for delayed job delivery.

## Job middleware

Middleware wraps `handle()`; return it from `Job::middleware()` (called on every run, never serialized):

```php
public function middleware(): array
{
    return [
        new WithoutOverlapping($this->locks(), key: 'reports', releaseAfter: 10, expireAfter: 300),
        new RateLimited($this->limiter(), key: 'mail', maxAttempts: 30, decaySeconds: 60),
    ];
}
```

A middleware that does not want the job to run now calls `$job->releaseAfter($seconds)` and returns without calling `$next`; the Worker puts the job back with that delay **without consuming an attempt**. Write your own by implementing `Middleware\JobMiddlewareInterface`.

`WithoutOverlapping` and `UniqueQueue` need a `Lock\JobLockInterface`: `InMemoryJobLock` (tests / single process) or `CacheJobLock` (needs `ez-php/cache`; use a shared store such as Redis or File). `RateLimited` needs `ez-php/rate-limiter`.

## Unique jobs

Implement `ShouldBeUnique` (`uniqueId()`, `uniqueFor()`) and wrap your queue so duplicates are dropped while one is pending or running:

```php
$queue = new UniqueQueue($innerQueue, $locks);   // same $locks as the Worker
// bind JobLockInterface in the container and QueueServiceProvider hands it to the Worker
```

The lock is released when the job succeeds or fails permanently, and survives retries. Note that the wrapper hides `FailedJobRepositoryInterface`, so `queue:failed` needs the unwrapped driver.

## Job chains

```php
JobChain::of(new Resize($id), new Publish($id), new Notify($id))->dispatch($queue);
```

Each job is pushed only after the previous one succeeded; if one fails for good the rest is dropped. Steps must extend `Job`.

## Running the Worker

```bash
php ez queue:work                  # process 'default' queue, sleep 3s on empty
php ez queue:work emails           # process 'emails' queue
php ez queue:work emails --sleep=5 # custom sleep interval
php ez queue:work --max-jobs=100   # stop after 100 jobs (useful for cron-based workers)
```

## Drivers

### Database Driver (default)

Stores jobs in a `jobs` table and failed jobs in `failed_jobs`. Both tables are created automatically. Requires a configured `DatabaseInterface` in the container.

Supports delayed delivery via `available_at` column.

### Redis Driver

Uses `ext-redis`. Jobs are pushed to `queues:{name}` (RPUSH) and consumed via LPOP (FIFO). Failed jobs are appended to `queues:failed:{name}`.

Delayed delivery is **not** enforced — jobs are queued immediately regardless of `$delay`.

### InMemory Driver (tests only)

Keeps jobs in a PHP array for the lifetime of the process — no MySQL, no Redis.
Set `QUEUE_DRIVER=memory`, or construct it directly:

```php
use EzPhp\Queue\Driver\InMemoryDriver;

$queue = new InMemoryDriver();
$queue->push(new SendWelcomeEmail($userId));

$queue->size();          // 1
$job = $queue->pop();    // SendWelcomeEmail
$queue->failedJobs();    // [] — assert on failures in tests
$queue->flush();         // reset between tests
```

- Honours `$queue` and `$delay`, matching the database driver (the Redis driver ignores `$delay`).
- Jobs are serialized on push just like the persistent drivers, so a job that cannot be
  serialized fails here too instead of passing tests and breaking in production.
- Does **not** implement `FailedJobRepositoryInterface`, so `queue:failed` is unavailable —
  an in-process store cannot retry or forget jobs across processes. Use `failedJobs()` instead.
- **Not for production.** Nothing is shared between processes, so a job pushed in a web
  request is invisible to a `queue:work` worker.

## Failed jobs

The database driver stores permanently failed jobs and exposes management commands:

```bash
php ez queue:failed list            # list all failed jobs
php ez queue:failed retry {id}      # re-queue a failed job
php ez queue:failed delete {id}     # delete a failed job record
php ez queue:failed flush           # delete all failed jobs
```

## Monitoring

```bash
php ez queue:monitor                        # snapshot of queue depths + failed count
php ez queue:monitor --queues=emails,sms    # specific queues
php ez queue:monitor --watch=5              # refresh every 5 seconds
```

## Scheduling

Register recurring jobs in a service provider's `boot()`:

```php
$scheduler = $app->make(\EzPhp\Queue\Scheduling\Scheduler::class);

$scheduler->job(SendDailyReport::class)->daily();
$scheduler->job(PruneTokens::class)->hourly();
$scheduler->job(SyncData::class)->everyMinutes(15);
$scheduler->job(CustomJob::class)->cron('30 6 * * 1');

// Full fluent API:
$scheduler->job(SendMinuteDigest::class)->everyMinute();
$scheduler->job(SweepCache::class)->hourlyAt(30);                  // minute 30 of every hour
$scheduler->job(SendDailyReport::class)->dailyAt('8:30');           // 08:30 every day
$scheduler->job(WeeklyDigest::class)->weekly();                     // Sunday at midnight
$scheduler->job(BillingRun::class)->weeklyOn(1, '6:00')             // Monday at 06:00
    ->description('Runs weekly billing');
```

Run `queue:schedule` every minute via system cron:

```
* * * * * php /var/www/html/ez queue:schedule
```

**No overlap prevention.** If a `queue:schedule` tick is still running when
the next one starts (many due tasks, a slow batch), both ticks evaluate
`dueJobs()` against the same due tasks and can push the same job twice —
this scheduler has no mutex layer. If that risk matters to your deployment,
run `queue:schedule` through `ez-php/scheduler`'s mutex-guarded executor
instead of cron directly; see "Reconciling with `ez-php/queue`'s own
scheduler" in `modules/scheduler/README.md`. The two schedulers are not
merged — this is a few lines of integration glue in your own bootstrap, not
a required dependency.

## Deployment

`docker/app/supervisord.conf` (root and per-module Docker scaffolds) only defines
`php-fpm` and `nginx` by default. An application that enables `ez-php/queue` needs a
running `queue:work` process — add a `[program:queue-worker]` block:

```ini
[program:queue-worker]
command=php /var/www/html/ez queue:work --sleep=3
directory=/var/www/html
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

Append the queue name(s) and `--max-jobs`/`--sleep` options to `command=` as needed
(see [Running the Worker](#running-the-worker)). Run multiple `[program:queue-worker-N]`
blocks for concurrent workers on the same queue.

## Classes

| Class | Description |
|---|---|
| `Job` | Abstract base class for all jobs |
| `Worker` | Pops and executes jobs; handles retries and permanent failures |
| `JobChain` | `JobChain::of(...)->dispatch($queue)` — sequential jobs |
| `ShouldBeUnique` / `UniqueQueue` | Dispatch-time de-duplication of jobs |
| `Middleware\JobMiddlewareInterface`, `WithoutOverlapping`, `RateLimited` | Job middleware |
| `Lock\JobLockInterface`, `InMemoryJobLock`, `CacheJobLock` | Locks used by `WithoutOverlapping` / `UniqueQueue` |
| `QueueServiceProvider` | Registers `QueueInterface` and `Worker` with the DI container |
| `Driver\DatabaseDriver` | PDO-backed driver with atomic pop, delayed delivery, and `FailedJobRepositoryInterface` |
| `Driver\RedisDriver` | Redis-backed driver via ext-redis |
| `FailedJobRepositoryInterface` | Contract for failed-job stores: `all()`, `retry()`, `forget()`, `flush()` |
| `Scheduling\Scheduler` | Registry of recurring jobs; evaluates due tasks by cron expression |
| `Scheduling\ScheduledTask` | Fluent builder: `everyMinute()`, `everyMinutes()`, `hourly()`, `hourlyAt()`, `daily()`, `dailyAt()`, `weekly()`, `weeklyOn()`, `cron()`, `description()` |
| `Console\WorkCommand` | `queue:work` CLI command |
| `Console\MonitorCommand` | `queue:monitor` CLI command |
| `Console\FailedCommand` | `queue:failed` CLI command |
| `Console\ScheduleRunCommand` | `queue:schedule` CLI command |
| `QueueException` | Base exception for queue errors |

## Setup (standalone development)

```bash
cp .env.example .env
./start.sh
```
