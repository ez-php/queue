# Changelog

All notable changes to `ez-php/queue` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `JobInterface` — contract for all queue jobs with a `handle(): void` method
- `QueueInterface` — driver contract with `push()`, `pop()`, `ack()`, and `fail()` methods
- `DatabaseDriver` — persists jobs in a `jobs` table; supports delayed dispatch and retry tracking
- `RedisDriver` — pushes and pops jobs from Redis lists via `ext-redis`; supports delayed dispatch with sorted sets
- `Worker` — pulls jobs from the queue in a loop; configurable sleep interval, max attempts, and backoff delay; handles exceptions without crashing
- `queue:work` console command — starts the worker with `--queue`, `--sleep`, `--tries`, and `--timeout` options
- `QueueServiceProvider` — resolves the configured driver, binds `QueueInterface`, and registers the `queue:work` command
- `QueueException` for driver initialization, serialization, and job execution failures
