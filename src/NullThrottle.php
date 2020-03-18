<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Promise;
use Amp\Success;

/**
 * Throttle implementation that never throttles promise throughput.
 */
final class NullThrottle implements Throttle
{
    public function await(Promise $promise): Promise
    {
        return new Success();
    }

    public function join(): Promise
    {
        return new Success();
    }

    public function isThrottling(): bool
    {
        return false;
    }

    public function getAwaiting(): array
    {
        return [];
    }

    public function countAwaiting(): int
    {
        return 0;
    }
}
