<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;

/**
 * Interface JsonPayloadJobInterface
 *
 * Opt-in JSON storage for a job: JobSerializer prefers toPayload()/fromPayload()
 * over PHP serialize() when a job implements this interface. The stored record
 * is then plain JSON (readable by non-PHP tooling) and restoring it never runs
 * unserialize() on data read back from Redis or the database.
 *
 * The attempt count is stored next to the payload and restored through
 * incrementAttempts(), so jobs need not include it themselves. Jobs that do not
 * implement the interface keep working unchanged (PHP-serialized). Only the
 * top-level job is JSON — jobs chained inside a JobChain stay serialized.
 *
 * @package EzPhp\Queue
 */
interface JsonPayloadJobInterface extends JobInterface
{
    /**
     * The job's state as a JSON-encodable array (scalars, arrays, null).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * Rebuild the job from toPayload() output.
     *
     * @param array<string, mixed> $payload
     *
     * @return static
     */
    public static function fromPayload(array $payload): static;
}
