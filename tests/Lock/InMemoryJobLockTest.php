<?php

declare(strict_types=1);

namespace Tests\Lock;

use EzPhp\Queue\Lock\InMemoryJobLock;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(InMemoryJobLock::class)]
final class InMemoryJobLockTest extends TestCase
{
    public function testAcquireSucceedsOnceUntilReleased(): void
    {
        $locks = new InMemoryJobLock();

        $this->assertTrue($locks->acquire('a', 60));
        $this->assertFalse($locks->acquire('a', 60));

        $locks->release('a');

        $this->assertTrue($locks->acquire('a', 60));
    }

    public function testKeysAreIndependent(): void
    {
        $locks = new InMemoryJobLock();

        $this->assertTrue($locks->acquire('a', 0));
        $this->assertTrue($locks->acquire('b', 0));
    }

    public function testZeroTtlNeverExpires(): void
    {
        $locks = new InMemoryJobLock();
        $locks->acquire('a', 0);

        $this->assertFalse($locks->acquire('a', 0));
    }

    public function testReleasingAnUnheldLockIsANoOp(): void
    {
        $locks = new InMemoryJobLock();
        $locks->release('missing');

        $this->assertTrue($locks->acquire('missing', 0));
    }
}
