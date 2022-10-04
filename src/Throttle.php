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
     * Awaits the specified Future. When the throttle is engaged, returns a Future that will not resolve until
     * the throttle disengages. When the throttle is disengaged, returns a Future that will resolve in the next tick.
     *
     * @param Future $future Future.
     *
     * @return Future A Future that resolves when the throttle disengages.
     */
    public function await(Future $future): Future;

    /**
     * Joins the primary fiber from a joining fiber such that the joining fiber will be throttled if the primary is
     * throttled, otherwise continues immediately. This method should be called continuously until it returns true,
     * otherwise two or more joining fibers may falsely assume they can use the throttle when there is only capacity
     * for one more. It is safe to call await() immediately after this method returns true.
     *
     * @return Future<bool> A Future that resolves when the throttle is disengaged. True if await() can be
     *     called, false if throttle may still be engaged.
     */
    public function join(): Future;

    /**
     * Gets a value indicating whether the throttle is currently engaged.
     *
     * @return bool True if the throttle is engaged, otherwise false.
     */
    public function isThrottling(): bool;

    /**
     * Gets the list of awaiting Futures that have not yet resolved.
     *
     * @return Future[] List of awaiting Futures.
     */
    public function getAwaiting(): array;

    /**
     * Counts the number of awaiting Futures that have not yet resolved.
     *
     * @return int Number of awaiting Futures.
     */
    public function countAwaiting(): int;
}
