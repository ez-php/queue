<?php

declare(strict_types=1);

namespace EzPhp\Queue;

/**
 * Interface ShouldBeUnique
 *
 * Marker for jobs that must not be queued twice while an identical one is still
 * pending or running. Enforced by `UniqueQueue` (lock taken on `push()`) and the
 * `Worker` (lock released once the job succeeded or failed for good).
 *
 * @package EzPhp\Queue
 */
interface ShouldBeUnique
{
    /**
     * Identity of the job: two jobs with the same id are considered duplicates.
     * Typically the job class plus the entity id it works on.
     *
     * @return string
     */
    public function uniqueId(): string;

    /**
     * Seconds after which the uniqueness lock expires on its own, so a lost job
     * cannot block its successors forever. 0 = never.
     *
     * @return int
     */
    public function uniqueFor(): int;
}
