<?php

declare(strict_types=1);

namespace Tests\Lock;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\Queue\Lock\CacheJobLock;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CacheJobLock::class)]
final class CacheJobLockTest extends TestCase
{
    protected function tearDown(): void
    {
        ArrayLock::reset();

        parent::tearDown();
    }

    public function testAcquireIsExclusiveUntilReleased(): void
    {
        $locks = new CacheJobLock(new ArrayDriver());

        $this->assertTrue($locks->acquire('job', 60));
        $this->assertFalse($locks->acquire('job', 60));

        $locks->release('job');

        $this->assertTrue($locks->acquire('job', 60));
    }

    public function testLockIsSharedAcrossInstancesOverTheSameStore(): void
    {
        $cache = new ArrayDriver();

        $this->assertTrue((new CacheJobLock($cache))->acquire('job', 60));
        $this->assertFalse((new CacheJobLock($cache))->acquire('job', 60));
    }

    public function testLateReleaseDoesNotDeleteALockTakenSinceByAnotherHolder(): void
    {
        $cache = new ArrayDriver();
        $old = new CacheJobLock($cache);

        $this->assertTrue($old->acquire('job', -1)); // expired as soon as taken
        $this->assertTrue((new CacheJobLock($cache))->acquire('job', 60));

        $old->release('job');

        $this->assertFalse((new CacheJobLock($cache))->acquire('job', 60));
    }

    public function testReleaseFromAnotherProcessStillReleases(): void
    {
        // UniqueQueue: the dispatcher acquires, the worker (another instance) releases.
        $cache = new ArrayDriver();

        $this->assertTrue((new CacheJobLock($cache))->acquire('job', 60));

        (new CacheJobLock($cache))->release('job');

        $this->assertTrue((new CacheJobLock($cache))->acquire('job', 60));
    }
}
