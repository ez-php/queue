<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\FailedJobRepositoryInterface;
use EzPhp\Queue\Job;
use EzPhp\Queue\QueueException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Named Job implementation for DatabaseDriver tests.
 * Anonymous classes cannot be unserialized, so a named class is required.
 */
final class DatabaseTestJob extends Job
{
    public function __construct(string $queue = 'default', int $delay = 0)
    {
        $this->queue = $queue;
        $this->delay = $delay;
    }

    public function handle(): void
    {
    }
}

#[CoversClass(DatabaseDriver::class)]
#[UsesClass(Job::class)]
#[UsesClass(QueueException::class)]
final class DatabaseDriverTest extends TestCase
{
    private PDO $pdo;

    private DatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->driver = new DatabaseDriver($this->pdo);
    }

    private function makeJob(string $queue = 'default', int $delay = 0): DatabaseTestJob
    {
        return new DatabaseTestJob($queue, $delay);
    }

    /**
     * @param string $sql
     *
     * @return PDOStatement
     */
    private function pdoQuery(string $sql): PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);

        return $stmt;
    }

    public function testTablesAreCreatedAutomatically(): void
    {
        $result = $this->pdoQuery("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('jobs', $result);
        $this->assertContains('failed_jobs', $result);
    }

    public function testPushInsertsRow(): void
    {
        $this->driver->push($this->makeJob());

        $count = (int) $this->pdoQuery('SELECT COUNT(*) FROM jobs')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->driver->pop());
    }

    public function testPushAndPop(): void
    {
        $job = $this->makeJob();
        $this->driver->push($job);

        $popped = $this->driver->pop();

        $this->assertInstanceOf(Job::class, $popped);
        $this->assertSame(0, $this->driver->size());
    }

    public function testPopDeletesRowFromTable(): void
    {
        $this->driver->push($this->makeJob());
        $this->driver->pop();

        $count = (int) $this->pdoQuery('SELECT COUNT(*) FROM jobs')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPopRespectsQueue(): void
    {
        $this->driver->push($this->makeJob('emails'));
        $this->driver->push($this->makeJob('default'));

        $popped = $this->driver->pop('emails');
        $this->assertNotNull($popped);
        $this->assertSame('emails', $popped->getQueue());

        $this->assertNull($this->driver->pop('emails'));
        $this->assertNotNull($this->driver->pop('default'));
    }

    public function testPopRespectsFifoOrder(): void
    {
        $first = $this->makeJob('default');
        $second = $this->makeJob('default');

        $this->driver->push($first);
        $this->driver->push($second);

        $popped = $this->driver->pop();
        $this->assertNotNull($popped);
        // Both jobs are of the same type; verify the attempt counter
        // starts at 0 (i.e. a fresh job was returned)
        $this->assertSame(0, $popped->getAttempts());
    }

    public function testPopHonoursDelay(): void
    {
        $job = $this->makeJob('default', 3600); // available in 1 hour
        $this->driver->push($job);

        $this->assertNull($this->driver->pop(), 'Delayed job should not be available yet');
        // size() counts only jobs whose available_at <= now(); delayed job is excluded
        $this->assertSame(0, $this->driver->size('default'));
    }

    public function testSizeCountsAvailableJobsOnly(): void
    {
        $this->driver->push($this->makeJob('default', 0));     // available now
        $this->driver->push($this->makeJob('default', 3600));  // delayed

        $this->assertSame(1, $this->driver->size('default'));
    }

    public function testSizeReturnsZeroForEmptyQueue(): void
    {
        $this->assertSame(0, $this->driver->size());
    }

    public function testFailedInsertsIntoFailedJobsTable(): void
    {
        $job = $this->makeJob();
        $e = new \RuntimeException('something went wrong');

        $this->driver->failed($job, $e);

        $row = $this->pdoQuery('SELECT * FROM failed_jobs')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('default', $row['queue']);
        $this->assertIsString($row['exception']);
        $this->assertStringContainsString('something went wrong', $row['exception']);
    }

    public function testJobSurvivesSerializeRoundTrip(): void
    {
        $job = $this->makeJob('critical');
        $job->incrementAttempts();
        $this->driver->push($job);

        $popped = $this->driver->pop('critical');

        $this->assertNotNull($popped);
        $this->assertSame('critical', $popped->getQueue());
        $this->assertSame(1, $popped->getAttempts());
    }

    // ─── FailedJobRepositoryInterface ─────────────────────────────────────────

    public function testAllReturnsEmptyArrayWhenNoFailedJobs(): void
    {
        $this->assertSame([], $this->driver->all());
    }

    public function testAllReturnsFailedJobRecords(): void
    {
        $this->driver->failed($this->makeJob(), new \RuntimeException('err1'));
        $this->driver->failed($this->makeJob('emails'), new \RuntimeException('err2'));

        $all = $this->driver->all();

        $this->assertCount(2, $all);
        $this->assertSame('default', $all[0]['queue']);
        $this->assertSame('emails', $all[1]['queue']);
        $this->assertStringContainsString('err1', $all[0]['exception']);
    }

    public function testRetryMovesJobBackToQueue(): void
    {
        $job = $this->makeJob();
        $this->driver->failed($job, new \RuntimeException('err'));

        $all = $this->driver->all();
        $id = $all[0]['id'];

        $result = $this->driver->retry($id, $this->driver);

        $this->assertTrue($result);
        $this->assertEmpty($this->driver->all());
        $this->assertSame(1, $this->driver->size());
    }

    public function testRetryReturnsFalseForUnknownId(): void
    {
        $result = $this->driver->retry(9999, $this->driver);

        $this->assertFalse($result);
    }

    public function testForgetDeletesSingleRecord(): void
    {
        $this->driver->failed($this->makeJob(), new \RuntimeException('e1'));
        $this->driver->failed($this->makeJob(), new \RuntimeException('e2'));

        $id = $this->driver->all()[0]['id'];
        $result = $this->driver->forget($id);

        $this->assertTrue($result);
        $this->assertCount(1, $this->driver->all());
    }

    public function testForgetReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->driver->forget(9999));
    }

    public function testFlushDeletesAllFailedJobs(): void
    {
        $this->driver->failed($this->makeJob(), new \RuntimeException('e1'));
        $this->driver->failed($this->makeJob(), new \RuntimeException('e2'));

        $this->driver->flush();

        $this->assertEmpty($this->driver->all());
    }

    public function testDriverImplementsFailedJobRepositoryInterface(): void
    {
        $this->assertInstanceOf(FailedJobRepositoryInterface::class, $this->driver);
    }
}
