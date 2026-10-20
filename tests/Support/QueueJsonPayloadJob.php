<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Queue\Job;
use EzPhp\Queue\JsonPayloadJobInterface;

/**
 * Job stored as JSON through JsonPayloadJobInterface.
 */
final class QueueJsonPayloadJob extends Job implements JsonPayloadJobInterface
{
    protected string $queue = 'json';

    /**
     * @param int          $orderId
     * @param list<string> $tags
     */
    public function __construct(
        public readonly int $orderId,
        public readonly array $tags = [],
    ) {
    }

    /**
     * No-op: the tests only exercise (de)serialization.
     */
    public function handle(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['order_id' => $this->orderId, 'tags' => $this->tags];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return static
     */
    public static function fromPayload(array $payload): static
    {
        $id = $payload['order_id'] ?? 0;
        $tags = $payload['tags'] ?? [];

        return new static(is_int($id) ? $id : 0, is_array($tags) ? array_values(array_filter($tags, 'is_string')) : []);
    }
}
