<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

/**
 * The exception that is thrown when a throttle receives too many promises.
 */
final class ThrottleOverloadException extends \LogicException
{
    // Intentionally empty.
}
