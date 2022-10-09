<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Future;

/**
 * Provides methods to throttle Future throughput based on implementation-defined constraints.
 */
interface Throttle
{
    /**
     * Watches the specified Future. When the throttle is engaged, returns a Future that will not resolve until
     * the throttle disengages. When the throttle is disengaged, returns a Future that will resolve in the next tick.
     * The returned future should always be awaited immediately to avoid overloading the throttle.
     *
     * @param Future $future Future.
     *
     * @return Future A Future that resolves when the throttle disengages.
     *
     * @throws ThrottleOverloadException The throttle would be overloaded by watching the unit of work.
     */
    public function watch(Future $future): Future;

    /**
     * Joins the primary fiber with a secondary fiber such that the secondary fiber will be throttled if the primary is
     * throttled, otherwise continues immediately. This method should be called continuously until it returns true,
     * otherwise two or more joining fibers may falsely assume they can use the throttle when there is only capacity
     * for one more. It is safe to call watch() immediately after this method returns true.
     *
     * @return Future<bool> A Future that resolves when the throttle is disengaged. True if watch() can be
     *     called, false if throttle may still be engaged.
     */
    public function join(): Future;

    /**
     * Gets a value indicating whether the throttle is currently engaged.
     *
     * Note: this method can be useful for debugging but is seldom needed otherwise.
     *
     * @return bool True if the throttle is engaged, otherwise false.
     */
    public function isThrottling(): bool;

    /**
     * Gets the list of watched Futures that have not yet completed.
     *
     * @return Future[] List of incomplete Futures.
     */
    public function getWatched(): array;

    /**
     * Counts the number of watched Futures that have not yet resolved.
     *
     * @return int Number of watched Futures.
     */
    public function countWatched(): int;
}
