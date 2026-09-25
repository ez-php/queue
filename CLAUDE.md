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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT`, `HEALTH_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

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
├── ShouldBeUnique.php              — Marker for jobs that must not be queued twice (uniqueId(), uniqueFor())
├── UniqueQueue.php                 — QueueInterface decorator: drops a duplicate ShouldBeUnique push while its lock is held
├── JobChain.php                    — Builder: JobChain::of($a, $b, $c)->dispatch($queue); next job is pushed after the previous succeeded
├── JobSerializer.php               — Shared storage envelope for all drivers: records every class in the payload, restores with allowed_classes limited to that list
├── Middleware/
│   ├── JobMiddlewareInterface.php  — handle(JobInterface, callable $next); wraps Job::handle() inside the Worker
│   ├── WithoutOverlapping.php      — Execution-time mutual exclusion per key via JobLockInterface; blocked jobs are released
│   └── RateLimited.php             — Throttles jobs per key via ez-php/rate-limiter (soft dependency); over-limit jobs are released
├── Lock/
│   ├── JobLockInterface.php        — acquire(key, ttl) / release(key); shared by WithoutOverlapping and UniqueQueue
│   ├── InMemoryJobLock.php         — process-local lock for tests and driver=memory
│   └── CacheJobLock.php            — ez-php/cache-backed lock (soft dependency), shared between web and worker processes
├── Driver/
│   ├── DatabaseDriver.php          — PDO-backed driver; atomic pop via transaction; supports delayed delivery; implements FailedJobRepositoryInterface
│   ├── RedisDriver.php             — ext-redis driver; RPUSH/LPOP; delayed jobs in a sorted set, moved when due
│   └── InMemoryDriver.php          — in-process driver for tests; honours queue + delay; no infrastructure
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
├── JobSerializerTest.php           — Covers JobSerializer: class list (nested objects, enums, chains), legacy envelopes, malformed payloads
├── Support/                        — QueuePayload* fixtures: a job with nested objects + an enum (one class per file for PSR-4)
├── JobTest.php                     — Covers Job: defaults, custom props, attempt counter, fail hook, serialization
├── WorkerTest.php                  — Covers Worker: runNextJob, success, retry, permanent failure, maxJobs stop
├── JobChainTest.php                — Covers JobChain: order, next-after-success, chain dropped on permanent failure
├── UniqueQueueTest.php             — Covers UniqueQueue + Worker lock release: duplicates, retries, success/failure release
├── Lock/                           — InMemoryJobLock and CacheJobLock (ArrayDriver) tests
├── Middleware/                     — Pipeline order, release-without-attempt, WithoutOverlapping, RateLimited (fixtures in MiddlewareFixtures.php)
├── Driver/
│   ├── DatabaseDriverTest.php      — Covers DatabaseDriver against SQLite :memory: (no MySQL needed)
│   ├── QueueJobPayloadRoundTripTest.php — Push/pop of a job with nested objects, an enum and a chain through all three drivers; failed-job retry
│   └── RedisDriverTest.php         — Covers RedisDriver incl. delayed/released jobs; skipped when ext-redis is unavailable
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

- `push()`: RPUSH — appends to tail; a job with `$delay > 0` is instead `ZADD`ed to `queues:delayed:{name}` (score = available-at time)
- `pop()`: moves due delayed jobs onto the list (atomic Lua script), then LPOP — removes from head (FIFO)
- `size()`: LLEN + due members of the delayed set (jobs available now)
- `failed()`: RPUSH to `queues:failed:{name}` with a serialised `[job, exception, trace, failed_at]` array

**Delay:** honoured — see Design Decisions ("RedisDriver honours `$delay` via a sorted set").

---

### QueueServiceProvider (`src/QueueServiceProvider.php`)

Binds `QueueInterface` to the driver selected by `config/queue.php`:

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `queue.driver` | string | `'database'` | `'database'`, `'redis'`, or `'memory'` (tests only) |
| `queue.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `queue.redis.port` | int | `6379` | Redis port |
| `queue.redis.database` | int | `0` | Redis database index |

Also binds `Worker` (autowired via `QueueInterface`).

`boot()` auto-registers `queue:work`, `queue:failed`, `queue:monitor` and `queue:schedule` when the container implements `CommandRegistryInterface` (the ez-php `Application` does).

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

`ScheduledTask` is a fluent builder that stores the job class and its cron expression. `isDue(\DateTimeImmutable)` checks whether the expression matches the given time, via `ez-php/support`'s `CronExpression::isDue()` — the same matcher `ez-php/scheduler` uses, instead of a private copy of it (supports `*`, `N`, `*/N`; a malformed expression is never due).

`ScheduleRunCommand` (`queue:schedule`) calls `$scheduler->dueNow()` and pushes each due job onto the queue. Run from a system cron every minute: `* * * * * php ez queue:schedule`.

---

## Design Decisions and Constraints

- **`pop()` deletes immediately (pop-and-delete)** — The database driver atomically SELECTs and DELETEs in one transaction. This means a worker crash between pop and handle loses the job. The trade-off is simplicity: no reserved_at column, no heartbeat, no stuck-job cleanup daemon. Retry is handled at the application level by re-pushing on failure.
- **Job state is serialised with `serialize()`** — The whole job object, including `$attempts`, is PHP-serialised. This makes re-queueing after failure trivial: push the same object back. The downside is PHP-only portability. JSON-based payloads are a future option but require jobs to implement a toArray/fromArray contract.
- **Auto-created tables in DatabaseDriver** — `CREATE TABLE IF NOT EXISTS` runs in the constructor. This is intentional for ease of use in development and testing. In production, users can also create the tables via their migration system using the DDL shown in the README.
- **RedisDriver honours `$delay` via a sorted set, without a daemon** — Delayed jobs are `ZADD`ed to `queues:delayed:{name}` (score = available-at Unix time); every `pop()` first runs one Lua script that moves up to 100 due members onto the `queues:{name}` list, so the move is atomic across concurrent workers and needs no separate polling process. Each delayed member gets a random `id` in its JSON envelope because sorted-set members are unique — two byte-identical jobs would otherwise merge into one. This is required, not optional: the Worker re-pushes released jobs (`RateLimited`, `WithoutOverlapping` → `releaseAfter()`) and retries with their delay, and a driver that ignored delays would pop them straight back in a tight loop.
- **Commands are auto-registered through `CommandRegistryInterface`, not `Application`** — `boot()` checks `$this->app instanceof CommandRegistryInterface` (an `ez-php/contracts` interface) before calling `registerCommand()`, so the module never imports the concrete `Application` class and stays usable with a plain `ContainerInterface`.
- **The storage envelope lists every class in the payload** — `JobSerializer` records all `O:`/`C:`/`E:` class names found in the serialized job and `pop()` passes exactly that list to `allowed_classes`. `allowed_classes` applies to every nested object, so restricting it to the top-level job class turned a `Mailable`, `PushMessage`, event or chained job into `__PHP_Incomplete_Class` (a `TypeError` on typed properties). The list is stored next to the payload, so it limits instantiation to what was pushed but does not protect against an attacker who can write queue rows — queue storage must only be written by `push()`/`failed()`. Envelopes without a `classes` key (written before this change) fall back to the top-level class.
- **`failed()` is on the interface** — Driver-specific failure stores (DB table vs Redis list) require the interface to expose a `failed()` method. The alternative (casting to a driver-specific interface in the Worker) would couple the Worker to concrete drivers.
- **`InMemoryDriver` serializes jobs even though it holds them in memory** — Storing the object by reference would be faster, but a job that cannot be serialized would then pass its tests against the in-memory driver and fail only against `DatabaseDriver`/`RedisDriver` in production. Round-tripping through `serialize()`/`unserialize()` makes the test double reproduce the real constraint, and means a popped job is a copy rather than the pushed instance.
- **`InMemoryDriver` honours `$delay`, like the other drivers** — It stores an `available_at` timestamp per entry, matching `DatabaseDriver`. A test double that ignored delay would let delay-dependent code pass here and break against the database driver.
- **`InMemoryDriver` does not implement `FailedJobRepositoryInterface`** — Failures are recorded in an array and exposed via `failedJobs()` for assertions. Implementing the repository contract would imply `queue:failed` support (retry/forget/flush across processes), which an in-process store cannot honestly provide. Resolving `FailedJobRepositoryInterface` with the memory driver active throws, exactly as it does with Redis.
- **Job middleware wraps `handle()` inside the `Worker`, not at dispatch.** `Job::middleware()` returns fresh middleware instances on every execution (they are never serialized with the job, which is why locks/limiters are passed in from the job's own `middleware()` method). Only `Job` subclasses get middleware; a bare `JobInterface` implementation runs without.
- **A middleware skips a job by calling `Job::releaseAfter($seconds)` and not calling `$next`.** The Worker then re-pushes a copy with that delay — floored at 1 second, because a 0-second release makes a still-blocked job available at once and the Worker would pop it straight back in a tight loop — and gives the attempt back (`Job::withRelease()`), so waiting for a lock never exhausts `maxTries`. Released jobs are deliberately not counted in `Worker::getStats()` — its array shape is public API. A middleware that skips *without* releasing leaves the job counted as processed.
- **`ez-php/cache` and `ez-php/rate-limiter` are soft dependencies (`require-dev` + `suggest`).** `Lock\CacheJobLock` and `Middleware\RateLimited` reference them; PSR-4 only loads those classes when an application uses them, so `ez-php/queue` itself stays free of both. `JobLockInterface` is owned by this module so the core pipeline never imports cache types.
- **Unique jobs lock on push, release in the Worker.** `UniqueQueue` (decorator) takes the lock; the `Worker` (given the same `JobLockInterface`, auto-injected by `QueueServiceProvider` when the interface is bound) releases it once the job succeeded or failed permanently. The lock is intentionally kept across retries and releases: copies the Worker re-pushes are recognised via `Job::isRequeued()`/`getAttempts()` and bypass the lock. `uniqueFor()` is the TTL safety net if a job is lost. Wrapping is opt-in and manual — the provider does not wrap `QueueInterface` itself, because a wrapper would break the `FailedJobRepositoryInterface` `instanceof` check behind `queue:failed`.
- **Chains travel inside the serialized payload.** `JobChain::dispatch()` pushes only the first job; the remaining jobs are a private `list<Job>` on it (`Job::withChain()`), so every driver works unchanged and no extra table is needed. The Worker pushes `Job::nextInChain()` after a successful run; a permanently failed job drops the rest of the chain (no failure callbacks, no batches/progress UI — deliberately out of scope). Chain steps must extend `Job`.
- **No static façade** — Queue dispatch is done via the injected `QueueInterface`. No `Queue::push()` static helper is provided. The framework's service locator pattern is not used here — call sites inject the interface.
- **`Scheduling\Scheduler` has no overlap prevention, unlike `ez-php/scheduler`.** Two overlapping `queue:schedule` cron ticks can both match the same due `ScheduledTask` and push its job twice — nothing here dedups within a matching minute. This is intentional scope, not an oversight: adding mutex support would duplicate `ez-php/scheduler`'s `MutexInterface`/driver design rather than reuse it, and the two schedulers' data models (job-class-based here vs. console-command-based there) are different enough that merging them would be a real package merge. Applications that need overlap prevention run `queue:schedule` through `ez-php/scheduler`'s mutex-guarded executor instead of cron directly — documented in `modules/scheduler/README.md` § "Reconciling with `ez-php/queue`'s own scheduler" and this package's own README § "Scheduling". No code dependency between the two packages either way.

---
- **`ez-php/framework` is a `require-dev` dependency only.** Nothing in `src/` imports a framework class — the module needs `ez-php/contracts` (`ServiceProvider`, `QueueInterface`, …), `ez-php/console` (commands) and `ez-php/support` (`CronExpression`, zero dependencies); the framework is only needed by the test suite (`ez-php/testing-application`). Declaring it in `require` would force the whole kernel onto anything that installs the queue.

## Testing Approach

- **`DatabaseDriverTest`** — Uses SQLite `:memory:` via plain `PDO`. No MySQL or Docker required. All driver behaviour (push, pop, delay, failed_jobs, serialization) is covered.
- **`RedisDriverTest`** — Requires a live Redis instance (available in Docker). Tests are skipped automatically if `ext-redis` is not loaded. Uses Redis database `1` to avoid colliding with application data, and deletes the `queues:delayed:*` sets in `setUp()` (popping only drains the lists). Delayed jobs are made due by rewriting their score, not by sleeping.
- **`InMemoryDriverTest`** — Covers the same contract surface as `DatabaseDriverTest` with no infrastructure at all: push/pop, FIFO order, per-queue isolation, delay handling, size, failure recording, and the serialization round-trip. Uses a named job fixture — anonymous classes cannot be unserialized.
- **`WorkerTest`** — Uses an in-memory `QueueInterface` stub (anonymous class). No external infrastructure needed. Tests cover: empty queue, success, retry on failure, permanent failure, maxJobs stopping.
- **`WorkCommandTest`** — Uses the same in-memory stub. Output is captured via `ob_start()`. Tests cover: getName, getDescription, getHelp, handle with queue/sleep/max-jobs options.
- **`JobTest`** — Pure unit tests. No infrastructure. Tests cover: defaults, custom properties, attempt counter, fail override, serialization roundtrip.
- **`#[CoversClass]` required** — `beStrictAboutCoverageMetadata=true` is set in phpunit.xml.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Job scheduling (cron-style) | `ez-php/scheduler` |
| Async / parallel execution | Application layer (pcntl, Amp, ReactPHP) |
| Retry backoff strategies (exponential, jitter) | Application layer (override `fail()` and re-push with modified `$delay`) |
| Queue monitoring / dashboard | Application layer |
| Rate-limiting logic itself | `ez-php/rate-limiter` (this module only ships the `Middleware\RateLimited` adapter) |
| Batches with progress tracking / dashboards | Application layer |
| Email sending (use case) | `ez-php/mail` |
| Priority queues | Future driver extension or application layer |
