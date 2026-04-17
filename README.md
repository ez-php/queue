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

Add the worker command to the CLI:

```php
$app->registerCommand(\EzPhp\Queue\Console\WorkCommand::class);
```

Add `config/queue.php` to your application:

```php
return [
    'driver' => env('QUEUE_DRIVER', 'database'),  // 'database' | 'redis'
    'redis'  => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'database' => (int) env('REDIS_DATABASE', 0),
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
```

Run `queue:schedule` every minute via system cron:

```
* * * * * php /var/www/html/ez queue:schedule
```

## Classes

| Class | Description |
|---|---|
| `Job` | Abstract base class for all jobs |
| `Worker` | Pops and executes jobs; handles retries and permanent failures |
| `QueueServiceProvider` | Registers `QueueInterface` and `Worker` with the DI container |
| `Driver\DatabaseDriver` | PDO-backed driver with atomic pop, delayed delivery, and `FailedJobRepositoryInterface` |
| `Driver\RedisDriver` | Redis-backed driver via ext-redis |
| `FailedJobRepositoryInterface` | Contract for failed-job stores: `all()`, `retry()`, `forget()`, `flush()` |
| `Scheduling\Scheduler` | Registry of recurring jobs; evaluates due tasks by cron expression |
| `Scheduling\ScheduledTask` | Fluent builder: `daily()`, `hourly()`, `everyMinutes()`, `cron()` |
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
