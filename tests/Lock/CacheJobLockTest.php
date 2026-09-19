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
}
