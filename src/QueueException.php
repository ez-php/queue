<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\EzPhpException;

/**
 * Class QueueException
 *
 * Base exception for all queue driver and worker errors.
 *
 * @package EzPhp\Queue
 */
final class QueueException extends EzPhpException
{
}
