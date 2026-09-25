<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Second-level nested object.
 */
final class QueuePayloadAddressBook
{
    /**
     * @param list<string> $entries
     */
    public function __construct(public readonly array $entries)
    {
    }
}
