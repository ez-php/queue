<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Enum carried inside a job payload — enums are subject to allowed_classes too.
 */
enum QueuePayloadPriority: string
{
    case High = 'high';
    case Low = 'low';
}
