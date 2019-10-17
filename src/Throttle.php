<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Deferred;
use Amp\Delayed;
use Amp\Loop;
use Amp\Promise;

/**
 * Throttles promise throughput based on two independent thresholds: number of concurrently executing promises
 * and number of promises awaited per second.
 */
class Throttle
{
    /**
     * Maximum number of promises per second.
     */
    public const DEFAULT_PER_SECOND = 75;

    /**
     * Maximum number of concurrent promises.
     */
    public const DEFAULT_CONCURRENCY = 30;

    /**
     * Milliseconds to wait before reevaluating thresholds when above chrono threshold.
     */
    private const RETRY_DELAY = 100;

    /**
     * List of unresolved promises.
     *
     * @var Promise[]
     */
    private $awaiting = [];

    /**
     * Promise that blocks whilst the throttle is engaged.
     *
     * @var Deferred|null
     */
    private $throttle;

    private $maxConcurrency;

    private $maxPerSecond;

    /**
     * Stack of timestamps when each promise was awaited.
     *
     * @var array
     */
    private $chronoStack = [];

    /**
     * Initializes this instance with the specified throttle limits.
     * If either limit is reached or exceeded, the throttle will become engaged, otherwise it is disengaged.
     *
     * @param int $maxPerSecond Optional. Maximum number of promises per second.
     * @param int $maxConcurrency Optional. Maximum number of concurrent promises.
     */
    public function __construct(
        int $maxPerSecond = self::DEFAULT_PER_SECOND,
        int $maxConcurrency = self::DEFAULT_CONCURRENCY
    ) {
        $this->maxPerSecond = $maxPerSecond;
        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * Awaits the specified promise. When the throttle is engaged, returns a promise that will not resolve until
     * the throttle disengages. When the throttle is disengaged, returns a promise that will resolve in the next tick.
     *
     * @param Promise $promise Promise.
     *
     * @return Promise A promise that resolves when the throttle disengages.
     */
    public function await(Promise $promise): Promise
    {
        if ($this->isThrottling()) {
            throw new \BadMethodCallException('Cannot await: throttle is engaged!');
        }

        $this->watch($promise);

        if ($this->tryFulfilPromises()) {
            /* Give consumers a chance to process the result before queuing another. Returning Success here forces
             * the throttle to become engaged before any processing can be done by the caller on the results. */
            return new Delayed(0);
        }

        $this->throttle = new Deferred;

        return $this->throttle->promise();
    }

    /**
     * Watches a promise to observe when it resolves.
     *
     * @param Promise $promise Promise.
     */
    private function watch(Promise $promise): void
    {
        $this->chronoStack[] = self::getTime();

        $this->awaiting[$hash = spl_object_hash($promise)] = $promise;

        $promise->onResolve(function () use ($hash): void {
            unset($this->awaiting[$hash]);

            $this->tryFulfilPromises();
        });
    }

    /**
     * Gets a value indicating whether the throttle is currently engaged.
     *
     * @return bool True if the throttle is engaged, otherwise false.
     */
    public function isThrottling(): bool
    {
        return $this->throttle !== null;
    }

    private function isBelowConcurrencyThreshold(): bool
    {
        return $this->countAwaiting() < $this->maxConcurrency;
    }

    private function isBelowChronoThreshold(): bool
    {
        $this->discardObsoleteChronoEntries();

        return count($this->chronoStack) < $this->maxPerSecond;
    }

    private function discardObsoleteChronoEntries(): void
    {
        while (isset($this->chronoStack[0]) && $this->chronoStack[0] < self::getTime() - 1) {
            array_shift($this->chronoStack);
        }
    }

    private function tryFulfilPromises(): bool
    {
        // Not throttled.
        if (($belowChronoThreshold = $this->isBelowChronoThreshold()) && $this->isBelowConcurrencyThreshold()) {
            if ($throttle = $this->throttle) {
                $this->throttle = null;
                $throttle->resolve();
            }

            return true;
        }

        if (!$belowChronoThreshold) {
            // Schedule function to be called recursively.
            Loop::delay(
                self::RETRY_DELAY,
                \Closure::fromCallable([$this, __FUNCTION__])
            );
        }

        return false;
    }

    /**
     * Gets the list of awaited promises that have not yet resolved.
     *
     * @return Promise[] List of awaited promises.
     */
    public function getAwaiting(): array
    {
        return $this->awaiting;
    }

    /**
     * Counts the number of awaited promises that have not yet resolved.
     *
     * @return int Number of awaited promises.
     */
    public function countAwaiting(): int
    {
        return \count($this->awaiting);
    }

    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * Sets the maximum number of concurrent promises that can be awaited and unresolved.
     *
     * @param int $maxConcurrency Maximum number of concurrent promises.
     */
    public function setMaxConcurrency(int $maxConcurrency): void
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    public function getMaxPerSecond(): int
    {
        return $this->maxPerSecond;
    }

    /**
     * Sets the maximum number of promises that can be processed per second.
     *
     * @param int $maxPerSecond Maximum number of promises per second.
     */
    public function setMaxPerSecond(int $maxPerSecond): void
    {
        $this->maxPerSecond = $maxPerSecond;
    }

    private static function getTime(): float
    {
        return microtime(true);
    }
}
