<?php

declare(strict_types=1);

namespace EzPhp\Queue\Driver;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\FailedJobRepositoryInterface;
use EzPhp\Queue\QueueException;
use PDO;

/**
 * Class DatabaseDriver
 *
 * PDO-backed queue driver. Jobs are stored in a `jobs` table and permanently
 * failed jobs in a `failed_jobs` table. Both tables are created automatically
 * if they do not exist (CREATE TABLE IF NOT EXISTS).
 *
 * pop() is atomic: it selects the oldest available job and deletes it within a
 * single transaction so that concurrent workers cannot pick the same job.
 *
 * Supports delayed jobs via the available_at column: a job with delay > 0 is
 * not returned by pop() until available_at <= NOW().
 *
 * Implements FailedJobRepositoryInterface to support the queue:failed command
 * (list / retry / delete / flush failed-job records).
 *
 * @package EzPhp\Queue\Driver
 */
final readonly class DatabaseDriver implements QueueInterface, FailedJobRepositoryInterface
{
    /**
     * DatabaseDriver Constructor
     *
     * @param PDO $pdo
     */
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTablesIfNeeded();
    }

    /**
     * @param JobInterface $job
     *
     * @return void
     */
    public function push(JobInterface $job): void
    {
        try {
            $payload = serialize($job);
        } catch (\Throwable $e) {
            throw new QueueException(
                'Job cannot be serialized: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $availableAt = time() + $job->getDelay();

        $this->pdo->prepare(
            'INSERT INTO jobs (queue, payload, available_at, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$job->getQueue(), $payload, $availableAt, time()]);
    }

    /**
     * Atomically pop the next available job from the given queue.
     *
     * Uses a transaction to SELECT + DELETE so no two workers process the same
     * job. Returns null when the queue is empty or no job is due yet.
     *
     * @param string $queue
     *
     * @return JobInterface|null
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, payload FROM jobs
                 WHERE queue = ? AND available_at <= ?
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([$queue, time()]);

            /** @var array{id: int|string, payload: string}|false $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$row['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new QueueException('Failed to pop job from database queue: ' . $e->getMessage(), 0, $e);
        }

        $job = unserialize($row['payload']);

        if (!$job instanceof JobInterface) {
            throw new QueueException('Deserialized payload is not a JobInterface instance.');
        }

        return $job;
    }

    /**
     * @param string $queue
     *
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE queue = ? AND available_at <= ?');
        $stmt->execute([$queue, time()]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Insert a record into failed_jobs.
     *
     * @param JobInterface $job
     * @param \Throwable   $exception
     *
     * @return void
     */
    public function failed(JobInterface $job, \Throwable $exception): void
    {
        $this->pdo->prepare(
            'INSERT INTO failed_jobs (queue, payload, exception, failed_at) VALUES (?, ?, ?, ?)'
        )->execute([
            $job->getQueue(),
            serialize($job),
            $exception->getMessage() . "\n" . $exception->getTraceAsString(),
            date('Y-m-d H:i:s'),
        ]);
    }

    // ─── FailedJobRepositoryInterface ─────────────────────────────────────────

    /**
     * Return all failed-job records in insertion order.
     *
     * @return list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, queue, payload, exception, failed_at FROM failed_jobs ORDER BY id ASC'
        );

        if ($stmt === false) {
            return [];
        }

        /** @var list<array{id: int, queue: string, payload: string, exception: string, failed_at: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Move a failed job back onto its original queue.
     *
     * Deserializes the stored payload, pushes the job back to the queue, then
     * removes the failed-job record. Returns false if the record does not exist
     * or the payload cannot be deserialized to a JobInterface.
     *
     * @param int            $id
     * @param QueueInterface $queue
     *
     * @return bool
     */
    public function retry(int $id, QueueInterface $queue): bool
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM failed_jobs WHERE id = ?');
        $stmt->execute([$id]);

        /** @var array{payload: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $job = unserialize($row['payload']);

        if (!$job instanceof JobInterface) {
            return false;
        }

        $queue->push($job);
        $this->pdo->prepare('DELETE FROM failed_jobs WHERE id = ?')->execute([$id]);

        return true;
    }

    /**
     * Permanently delete a single failed-job record.
     *
     * @param int $id
     *
     * @return bool Returns false if no record with that id exists.
     */
    public function forget(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM failed_jobs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete every failed-job record.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->pdo->exec('DELETE FROM failed_jobs');
    }

    // ─── table setup ──────────────────────────────────────────────────────────

    /**
     * Create jobs and failed_jobs tables if they do not already exist.
     *
     * Uses driver-aware DDL to support both MySQL and SQLite.
     *
     * @return void
     */
    private function createTablesIfNeeded(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS jobs (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue      TEXT    NOT NULL DEFAULT \'default\',
                    payload    TEXT    NOT NULL,
                    available_at INTEGER NOT NULL,
                    created_at  INTEGER NOT NULL
                )'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS failed_jobs (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue      TEXT    NOT NULL,
                    payload    TEXT    NOT NULL,
                    exception  TEXT    NOT NULL,
                    failed_at  TEXT    NOT NULL
                )'
            );
        } else {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS jobs (
                    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    queue        VARCHAR(255) NOT NULL DEFAULT \'default\',
                    payload      LONGTEXT     NOT NULL,
                    available_at INT UNSIGNED NOT NULL,
                    created_at   INT UNSIGNED NOT NULL,
                    INDEX jobs_queue_available_idx (queue, available_at)
                )'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS failed_jobs (
                    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    queue      VARCHAR(255) NOT NULL,
                    payload    LONGTEXT     NOT NULL,
                    exception  TEXT         NOT NULL,
                    failed_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
        }
    }
}
