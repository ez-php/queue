<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Queue\Job;

/**
 * Job holding a typed object property and an enum — the shape of
 * SendMailableJob / SendPushNotificationJob / AsyncEventJob.
 */
final class QueuePayloadJob extends Job
{
    /**
     * @param QueuePayloadRecipient $recipient
     * @param QueuePayloadPriority  $priority
     */
    public function __construct(
        public readonly QueuePayloadRecipient $recipient,
        public readonly QueuePayloadPriority $priority = QueuePayloadPriority::High,
    ) {
    }

    /**
     * No-op: the tests only exercise (de)serialization.
     */
    public function handle(): void
    {
    }

    /**
     * Build an instance with two levels of nested objects.
     */
    public static function make(string $address = 'a@example.com'): self
    {
        return new self(new QueuePayloadRecipient($address, new QueuePayloadAddressBook([$address])));
    }
}
