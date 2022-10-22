<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Future;

/**
 * Provides methods to throttle work throughput based on implementation-defined constraints.
 */
interface Throttle
{
    /**
     * Runs the specified unit of work asynchronously on a separate fiber. When the throttle is disengaged, returns a
     * Future that will complete when the unit of work completes. When the throttle is engaged, returns a Future that
     * completes when the throttle disengages and the unit of work has completed.
     *
     * @param \Closure $unitOfWork Unit of work.
     * @param mixed $args Optional. Arguments to pass to the unit of work closure.
     *
     * @return Future A Future that completes with the return value of the unit of work after the throttle disengages.
     */
    public function async(\Closure $unitOfWork, mixed ...$args): Future;

    /**
     * Gets a value indicating whether the throttle is currently engaged.
     *
     * Note: this method can be useful for debugging but is seldom needed otherwise.
     *
     * @return bool True if the throttle is engaged, otherwise false.
     */
    public function isThrottling(): bool;

    /**
     * Gets the list of pending Futures that have not yet completed.
     *
     * @return Future[] List of pending Futures.
     */
    public function getPending(): array;

    /**
     * Counts the number of pending Futures that have not yet completed.
     *
     * @return int Number of pending Futures.
     */
    public function countPending(): int;
}
