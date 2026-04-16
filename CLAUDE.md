# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^1.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3311** | **6383** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/queue

Async job queue for ez-php applications — database and Redis drivers, a Worker loop, failed-job management, a cron-style Scheduler, and console commands (`queue:work`, `queue:failed`, `queue:schedule`).

---

## Source Structure

```
src/
├── Job.php                         — Abstract base class for all jobs; implements JobInterface
├── Worker.php                      — Pops and executes jobs; handles retries and permanent failures
├── QueueException.php              — Base exception for all queue errors
├── QueueServiceProvider.php        — Binds QueueInterface and Worker to the DI container
├── FailedJobRepositoryInterface.php — Contract for failed-job stores: all/retry/forget/flush
├── Driver/
│   ├── DatabaseDriver.php          — PDO-backed driver; atomic pop via transaction; supports delayed delivery; implements FailedJobRepositoryInterface
│   └── RedisDriver.php             — ext-redis driver; RPUSH/LPOP; no delay enforcement
├── Scheduling/
│   ├── Scheduler.php               — Registry of recurring jobs; evaluates due tasks by cron expression
│   └── ScheduledTask.php           — Fluent builder for a single scheduled job: everyMinutes/hourly/daily/cron
└── Console/
    ├── WorkCommand.php             — queue:work CLI command; wraps Worker::work(); prints stats summary on exit
    ├── MonitorCommand.php          — queue:monitor CLI command; prints queue depth + failed-job snapshot; supports --queues and --watch
    ├── FailedCommand.php           — queue:failed list|retry|delete|flush; manages the failed-job archive
    └── ScheduleRunCommand.php      — queue:schedule; pushes due scheduled tasks onto the queue

tests/
├── TestCase.php                    — Base PHPUnit test case
├── JobTest.php                     — Covers Job: defaults, custom props, attempt counter, fail hook, serialization
├── WorkerTest.php                  — Covers Worker: runNextJob, success, retry, permanent failure, maxJobs stop
├── Driver/
│   ├── DatabaseDriverTest.php      — Covers DatabaseDriver against SQLite :memory: (no MySQL needed)
│   └── RedisDriverTest.php         — Covers RedisDriver; skipped when ext-redis is unavailable
├── Scheduling/
│   ├── ScheduledTaskTest.php       — Covers ScheduledTask: cron/daily/hourly/everyMinutes, isDue()
│   └── SchedulerTest.php           — Covers Scheduler: task registration, dueNow(), job class resolution
├── Console/
│   ├── WorkCommandTest.php         — Covers WorkCommand: getName, output, maxJobs, queue name, stats summary
│   ├── MonitorCommandTest.php      — Covers MonitorCommand: getName, output, queue depths, failed count, --queues option
│   ├── FailedCommandTest.php       — Covers FailedCommand: list, retry, delete, flush subcommands
│   └── ScheduleRunCommandTest.php  — Covers ScheduleRunCommand: due task dispatch, no-tasks output
└── Integration/
    └── WorkerLifecycleTest.php     — Integration: Worker + DatabaseDriver (SQLite) full job lifecycle
```

---

## Key Classes and Responsibilities

### Job (`src/Job.php`)

Abstract base class. Subclasses implement `handle()` with the actual work. Configuration via protected properties:

| Property | Default | Meaning |
|---|---|---|
| `$queue` | `'default'` | Queue name this job is dispatched to |
| `$delay` | `0` | Seconds before the job becomes available |
| `$maxTries` | `3` | Maximum execution attempts before permanent failure |

`$attempts` is private and only accessible via `getAttempts()` / `incrementAttempts()`. It is serialised with the job so attempt counts survive re-queue cycles.

`fail(\Throwable $exception): void` is a no-op by default — override for notifications or cleanup on permanent failure.

---

### Worker (`src/Worker.php`)

Pops and processes jobs one at a time from a `QueueInterface`.

| Method | Behaviour |
|---|---|
| `runNextJob(string\|list<string> $queues)` | Pops one job from the first non-empty queue, calls `process()`, returns `true`; returns `false` if all queues empty |
| `work(string\|list<string> $queues, int $sleep, int $maxJobs)` | Loop: calls `runNextJob()`; sleeps `$sleep` seconds on empty; stops after `$maxJobs` (0 = infinite); resets stats counters on entry |
| `process(JobInterface $job)` | Increments attempts, calls `handle()`; on exception: re-queues if retries remain, marks failed otherwise |
| `getStats()` | Returns `{processed, retried, failed}` counters accumulated since the last `work()` call |

Retry logic: if `getAttempts() < getMaxTries()`, the job is re-pushed with its current state (including incremented attempt count, since the whole object is serialised). On exhaustion, `$queue->failed()` is called.

---

### DatabaseDriver (`src/Driver/DatabaseDriver.php`)

PDO-backed driver. Auto-creates `jobs` and `failed_jobs` tables (driver-aware DDL for MySQL and SQLite).

**pop() atomicity:** The SELECT and DELETE run inside a PDO transaction. If two workers race, one will win the SELECT and the other will find nothing. No advisory locks are used — the transaction isolation level must be at least `READ COMMITTED`.

| Method | Behaviour |
|---|---|
| `push(job)` | INSERT with `available_at = time() + delay` |
| `pop(queue)` | SELECT oldest available + DELETE in transaction; returns `null` if empty/not yet due |
| `size(queue)` | COUNT(*) where `available_at <= time()` |
| `failed(job, e)` | INSERT into `failed_jobs` with serialised job, exception message, and full stack trace |

---

### RedisDriver (`src/Driver/RedisDriver.php`)

ext-redis driver. Queues are Redis lists (`queues:{name}`). Failed jobs go to `queues:failed:{name}`.

- `push()`: RPUSH — appends to tail
- `pop()`: LPOP — removes from head (FIFO)
- `size()`: LLEN
- `failed()`: RPUSH to `queues:failed:{name}` with a serialised `[job, exception, trace, failed_at]` array

**Delay:** The `$delay` property is ignored. Jobs are always pushed immediately. Use `DatabaseDriver` if deferred delivery is required.

---

### QueueServiceProvider (`src/QueueServiceProvider.php`)

Binds `QueueInterface` to the driver selected by `config/queue.php`:

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `queue.driver` | string | `'database'` | `'database'` or `'redis'` |
| `queue.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `queue.redis.port` | int | `6379` | Redis port |
| `queue.redis.database` | int | `0` | Redis database index |

Also binds `Worker` (autowired via `QueueInterface`).

`WorkCommand` is **not** auto-registered. Call `$app->registerCommand(WorkCommand::class)` before bootstrapping to add `queue:work` to the CLI.

---

### WorkCommand (`src/Console/WorkCommand.php`)

Wraps `Worker::work()`. Parses args via `ez-php/console`'s `Input`.

```
ez queue:work [queue] [--sleep=3] [--max-jobs=0]
```

| Arg / option | Default | Meaning |
|---|---|---|
| `queue` (positional) | `'default'` | Comma-separated queue names in priority order |
| `--sleep=N` | `3` | Seconds to sleep on empty queue |
| `--max-jobs=N` | `0` | Stop after N jobs; 0 = run forever |

On exit, prints a stats summary: `Done. Processed: N | Retried: N | Permanently failed: N`.

---

### MonitorCommand (`src/Console/MonitorCommand.php`)

Console command `queue:monitor`. Prints a snapshot of queue depths and the failed-job count.

```
ez queue:monitor [--queues=default] [--watch=0]
```

| Option | Default | Meaning |
|---|---|---|
| `--queues=q1,q2` | `'default'` | Comma-separated queue names to inspect |
| `--watch=N` | `0` | Refresh every N seconds; 0 = single snapshot and exit |

When the queue driver implements `FailedJobRepositoryInterface`, the failed-job count is shown; otherwise `n/a` is displayed.

---

### FailedJobRepositoryInterface (`src/FailedJobRepositoryInterface.php`)

Contract for failed-job stores. `DatabaseDriver` implements it; `RedisDriver` does not.

| Method | Behaviour |
|---|---|
| `all()` | Returns all failed job rows (id, queue, payload, exception, failed_at) |
| `retry(int $id, QueueInterface $queue)` | Unserialises the job and re-pushes it; returns false if not found |
| `forget(int $id)` | Deletes the record; returns false if not found |
| `flush()` | Deletes all failed-job records |

---

### FailedCommand (`src/Console/FailedCommand.php`)

Console command `queue:failed`. Manages permanently failed jobs.

```
ez queue:failed list
ez queue:failed retry {id}
ez queue:failed delete {id}
ez queue:failed flush
```

Requires the active queue driver to implement `FailedJobRepositoryInterface` (e.g. `DatabaseDriver`).

---

### Scheduler + ScheduledTask (`src/Scheduling/`)

`Scheduler` is a registry for recurring jobs. Application code registers tasks during `boot()`:

```php
$scheduler->job(SendDailyReport::class)->daily();
$scheduler->job(PruneTokens::class)->hourly();
$scheduler->job(SyncData::class)->everyMinutes(15);
$scheduler->job(CustomJob::class)->cron('30 6 * * 1');
```

`ScheduledTask` is a fluent builder that stores the job class and its cron expression. `isDue(\DateTimeImmutable)` checks whether the expression matches the given time.

`ScheduleRunCommand` (`queue:schedule`) calls `$scheduler->dueNow()` and pushes each due job onto the queue. Run from a system cron every minute: `* * * * * php ez queue:schedule`.

---

## Design Decisions and Constraints

- **`pop()` deletes immediately (pop-and-delete)** — The database driver atomically SELECTs and DELETEs in one transaction. This means a worker crash between pop and handle loses the job. The trade-off is simplicity: no reserved_at column, no heartbeat, no stuck-job cleanup daemon. Retry is handled at the application level by re-pushing on failure.
- **Job state is serialised with `serialize()`** — The whole job object, including `$attempts`, is PHP-serialised. This makes re-queueing after failure trivial: push the same object back. The downside is PHP-only portability. JSON-based payloads are a future option but require jobs to implement a toArray/fromArray contract.
- **Auto-created tables in DatabaseDriver** — `CREATE TABLE IF NOT EXISTS` runs in the constructor. This is intentional for ease of use in development and testing. In production, users can also create the tables via their migration system using the DDL shown in the README.
- **RedisDriver ignores `$delay`** — Redis lists have no native deferred-delivery mechanism without sorted sets + a polling daemon. Adding that complexity to a v1 driver is premature. The `$delay` property is preserved on the job object (serialised), so switching to `DatabaseDriver` later respects whatever delay was configured.
- **`WorkCommand` is not auto-registered** — Module service providers cannot call `$app->registerCommand()` directly (it is an `Application` method, not on `ContainerInterface`). Users register the command explicitly, keeping the module decoupled from the concrete `Application` class.
- **`failed()` is on the interface** — Driver-specific failure stores (DB table vs Redis list) require the interface to expose a `failed()` method. The alternative (casting to a driver-specific interface in the Worker) would couple the Worker to concrete drivers.
- **No static façade** — Queue dispatch is done via the injected `QueueInterface`. No `Queue::push()` static helper is provided. The framework's service locator pattern is not used here — call sites inject the interface.

---

## Testing Approach

- **`DatabaseDriverTest`** — Uses SQLite `:memory:` via plain `PDO`. No MySQL or Docker required. All driver behaviour (push, pop, delay, failed_jobs, serialization) is covered.
- **`RedisDriverTest`** — Requires a live Redis instance (available in Docker). Tests are skipped automatically if `ext-redis` is not loaded. Uses Redis database `1` to avoid colliding with application data.
- **`WorkerTest`** — Uses an in-memory `QueueInterface` stub (anonymous class). No external infrastructure needed. Tests cover: empty queue, success, retry on failure, permanent failure, maxJobs stopping.
- **`WorkCommandTest`** — Uses the same in-memory stub. Output is captured via `ob_start()`. Tests cover: getName, getDescription, getHelp, handle with queue/sleep/max-jobs options.
- **`JobTest`** — Pure unit tests. No infrastructure. Tests cover: defaults, custom properties, attempt counter, fail override, serialization roundtrip.
- **`#[CoversClass]` required** — `beStrictAboutCoverageMetadata=true` is set in phpunit.xml.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Job scheduling (cron-style) | Application layer or a future `ez-php/scheduler` module |
| Async / parallel execution | Application layer (pcntl, Amp, ReactPHP) |
| Retry backoff strategies (exponential, jitter) | Application layer (override `fail()` and re-push with modified `$delay`) |
| Queue monitoring / dashboard | Application layer |
| Rate limiting of job processing | `ez-php/rate-limiter` |
| Email sending (use case) | `ez-php/mail` |
| Priority queues | Future driver extension or application layer |
