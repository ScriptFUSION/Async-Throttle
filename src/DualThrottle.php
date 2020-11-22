<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Deferred;
use Amp\Delayed;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

/**
 * Throttles promise throughput based on two independent thresholds: number of concurrently executing promises
 * and number of promises awaited per second.
 */
class DualThrottle implements Throttle
{
    /**
     * Default maximum number of promises per second.
     */
    public const DEFAULT_PER_SECOND = 75;

    /**
     * Default maximum number of concurrent promises.
     */
    public const DEFAULT_CONCURRENCY = 30;

    /**
     * Milliseconds to wait before reevaluating thresholds when above chrono threshold.
     */
    private const RETRY_DELAY = 100;

    /**
     * @var Promise[] List of unresolved promises.
     */
    private $awaiting = [];

    /**
     * @var Deferred|null Promise that blocks whilst the throttle is engaged.
     */
    private $throttle;

    /**
     * @var int Maximum number of promises per second.
     */
    private $maxConcurrency;

    /**
     * @var int Maximum number of concurrent promises.
     */
    private $maxPerSecond;

    /**
     * @var array Stack of timestamps when each promise was awaited.
     */
    private $chronoStack = [];

    /**
     * Initializes this instance with the specified thresholds.
     * If either threshold is reached or exceeded, the throttle will become engaged, otherwise it is disengaged.
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

    public function await(Promise $promise): Promise
    {
        if ($this->isThrottling()) {
            throw new ThrottleOverloadException('Cannot await: throttle is engaged!');
        }

        $this->watch($promise);

        if ($this->tryDisengageThrottle()) {
            /* Give consumers a chance to process the result before queuing another. Returning Success here forces
             * the throttle to become engaged before any processing can be done by the caller on the results. */
            return new Delayed(0);
        }

        $this->throttle = new Deferred;

        return $this->throttle->promise();
    }

    public function join(): Promise
    {
        if ($this->isThrottling()) {
            return $this->throttle->promise();
        }

        return new Success(true);
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

            $this->tryDisengageThrottle();
        });
    }

    public function isThrottling(): bool
    {
        return $this->throttle !== null;
    }

    /**
     * Gets a value indicating whether the concurrency threshold has been met or exceeded.
     *
     * @return bool True if below the concurrency threshold, otherwise false.
     */
    private function isBelowConcurrencyThreshold(): bool
    {
        return $this->countAwaiting() < $this->maxConcurrency;
    }

    /**
     * Gets a value indicating whether the chrono threshold has been met or exceeded.
     *
     * @return bool True if below the chrono threshold, otherwise false.
     */
    private function isBelowChronoThreshold(): bool
    {
        $this->removeObsoleteChronoEntries();

        return count($this->chronoStack) < $this->maxPerSecond;
    }

    /**
     * Removes obsolete entries from the chrono stack. An entry is considered obsolete if it occurred more than one
     * second ago.
     */
    private function removeObsoleteChronoEntries(): void
    {
        while (isset($this->chronoStack[0]) && $this->chronoStack[0] < self::getTime() - 1) {
            array_shift($this->chronoStack);
        }
    }

    /**
     * Tries to disengage the throttle. Throttle can only be disengaged when neither threshold is exceeded.
     * When the chrono threshold is exceeded, this function is automatically re-queued until falls below the chrono
     * threshold.
     *
     * @return bool True if throttle is disengaged, false if throttle should be engaged (whether or not it is).
     */
    private function tryDisengageThrottle(): bool
    {
        // Not throttled.
        if (($belowChronoThreshold = $this->isBelowChronoThreshold()) && $this->isBelowConcurrencyThreshold()) {
            // Disengage.
            if ($throttle = $this->throttle) {
                $this->throttle = null;
                $throttle->resolve(false);
            }

            return true;
        }

        // Above chrono threshold.
        if (!$belowChronoThreshold) {
            // Schedule function to be called recursively.
            Loop::delay(
                self::RETRY_DELAY,
                \Closure::fromCallable([$this, __FUNCTION__])
            );
        }

        return false;
    }

    public function getAwaiting(): array
    {
        return $this->awaiting;
    }

    public function countAwaiting(): int
    {
        return \count($this->awaiting);
    }

    /**
     * Measures promise throughput in promises/second.
     *
     * @return int Promises per second.
     */
    public function measureThroughput(): int
    {
        $this->removeObsoleteChronoEntries();

        return \count($this->chronoStack);
    }

    /**
     * Gets the maximum number of concurrent promises that can be awaiting and unresolved.
     *
     * @return int Maximum number of concurrent promises.
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * Sets the maximum number of concurrent promises that can be awaiting and unresolved.
     *
     * @param int $maxConcurrency Maximum number of concurrent promises.
     */
    public function setMaxConcurrency(int $maxConcurrency): void
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * Gets the maximum number of promises that can be awaited per second.
     *
     * @return int Maximum number of promises per second.
     */
    public function getMaxPerSecond(): int
    {
        return $this->maxPerSecond;
    }

    /**
     * Sets the maximum number of promises that can be awaited per second.
     *
     * @param int $maxPerSecond Maximum number of promises per second.
     */
    public function setMaxPerSecond(int $maxPerSecond): void
    {
        $this->maxPerSecond = $maxPerSecond;
    }

    /**
     * Gets the current time in seconds.
     *
     * @return float Time.
     */
    private static function getTime(): float
    {
        return microtime(true);
    }
}
