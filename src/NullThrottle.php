<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Future;

/**
 * Throttle implementation that never throttles work throughput.
 */
final class NullThrottle implements Throttle
{
    public function watch(\Closure $unitOfWork, mixed ...$args): Future
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

    public function getWatched(): array
    {
        return [];
    }

    public function countWatched(): int
    {
        return 0;
    }
}
