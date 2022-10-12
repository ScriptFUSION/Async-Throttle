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
     * Watches the specified unit of work. When the throttle is engaged, returns a Future that will not resolve until
     * the throttle disengages. When the throttle is disengaged, returns a Future that will resolve in the next tick.
     * The returned future should always be awaited immediately to avoid overloading the throttle.
     *
     * @param \Closure $unitOfWork Unit of work.
     * @param mixed $args Optional. Arguments to pass to the unit of work closure.
     *
     * @return Future A Future that resolves when the throttle disengages.
     */
    public function watch(\Closure $unitOfWork, mixed ...$args): Future;

    /**
     * Gets a value indicating whether the throttle is currently engaged.
     *
     * Note: this method can be useful for debugging but is seldom needed otherwise.
     *
     * @return bool True if the throttle is engaged, otherwise false.
     */
    public function isThrottling(): bool;

    /**
     * Gets the watched units of work that have not yet completed, as a list of Futures.
     *
     * @return Future[] List of incomplete units of work.
     */
    public function getWatched(): array;

    /**
     * Counts the number of watched units of work that have not yet resolved.
     *
     * @return int Number of watched units of work.
     */
    public function countWatched(): int;
}
