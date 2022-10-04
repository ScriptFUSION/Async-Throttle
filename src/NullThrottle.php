<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Future;

/**
 * Throttle implementation that never throttles Future throughput.
 */
final class NullThrottle implements Throttle
{
    public function await(Future $future): Future
    {
        return Future::complete();
    }

    public function join(): Future
    {
        return Future::complete(true);
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
