<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Future;
use function Amp\async;

/**
 * Throttle implementation that never throttles work throughput.
 */
final class NullThrottle implements Throttle
{
    public function async(\Closure $unitOfWork, mixed ...$args): Future
    {
        return async($unitOfWork, ...$args);
    }

    public function isThrottling(): bool
    {
        return false;
    }

    public function getPending(): array
    {
        return [];
    }

    public function countPending(): int
    {
        return 0;
    }
}
