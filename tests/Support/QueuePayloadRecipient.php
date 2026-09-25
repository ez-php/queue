<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Plain value object nested inside a job, like a Mailable or PushMessage.
 */
final class QueuePayloadRecipient
{
    /**
     * @param string                  $address
     * @param QueuePayloadAddressBook $book    A second level of nesting.
     */
    public function __construct(
        public readonly string $address,
        public readonly QueuePayloadAddressBook $book,
    ) {
    }
}
