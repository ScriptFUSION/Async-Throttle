<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\DeferredFuture;
use Amp\Future;
use function Amp\async;
use function Amp\delay;

/**
 * Throttles Future throughput based on two independent thresholds: number of concurrently executing Futures
 * and number of Futures awaited per second.
 */
class DualThrottle implements Throttle
{
    /**
     * Default maximum number of Futures per second.
     */
    public const DEFAULT_PER_SECOND = 75;

    /**
     * Default maximum number of concurrent Futures.
     */
    public const DEFAULT_CONCURRENCY = 30;

    /**
     * Seconds to wait before reevaluating thresholds when above chrono threshold.
     */
    private const RETRY_DELAY = .1;

    /**
     * @var Future[] List of unresolved Futures.
     */
    private array $awaiting = [];

    /**
     * @var DeferredFuture|null Future that blocks whilst the throttle is engaged.
     */
    private ?DeferredFuture $throttle = null;

    /**
     * @var int Maximum number of Futures per second.
     */
    private int $maxConcurrency;

    /**
     * @var float Maximum number of concurrent Futures.
     */
    private float $maxPerSecond;

    /**
     * @var array Stack of timestamps when each Future was awaited.
     */
    private array $chronoStack = [];

    /**
     * Initializes this instance with the specified thresholds.
     * If either threshold is reached or exceeded, the throttle will become engaged, otherwise it is disengaged.
     *
     * @param float $maxPerSecond Optional. Maximum number of Futures per second.
     * @param int $maxConcurrency Optional. Maximum number of concurrent Futures.
     */
    public function __construct(
        float $maxPerSecond = self::DEFAULT_PER_SECOND,
        int $maxConcurrency = self::DEFAULT_CONCURRENCY
    ) {
        $this->maxPerSecond = $maxPerSecond;
        $this->maxConcurrency = $maxConcurrency;
    }

    public function await(Future $future): Future
    {
        if ($this->isThrottling()) {
            throw new ThrottleOverloadException('Cannot await: throttle is engaged!');
        }

        $this->watch($future);

        if ($this->tryDisengageThrottle()) {
            /* Give consumers a chance to process the result before queuing another. Returning Future::complete here
             * forces the throttle to become engaged before any processing can be done by the caller on the results. */
            return async(delay(...), 0);
        }

        $this->throttle = new DeferredFuture();

        return $this->throttle->getFuture();
    }

    public function join(): Future
    {
        if ($this->isThrottling()) {
            return $this->throttle->getFuture();
        }

        return Future::complete(true);
    }

    /**
     * Watches a future to observe when it resolves.
     *
     * @param Future $future Future.
     */
    private function watch(Future $future): void
    {
        $this->chronoStack[] = self::getTime();

        $this->awaiting[$hash = spl_object_id($future)] = $future;

        $future->finally(function () use ($hash): void {
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
     * Removes obsolete entries from the chrono stack.
     *
     * When the maximum number of Futures per second >= 1, an entry is considered obsolete when it occurred more than
     * one second ago, otherwise it is obsolete when the reciprocal of the maximum number of Futures per second has
     * elapsed.
     */
    private function removeObsoleteChronoEntries(): void
    {
        while (isset($this->chronoStack[0]) &&
            $this->chronoStack[0] < self::getTime() - max(1, 1 / $this->maxPerSecond)
        ) {
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
                $throttle->complete(false);
            }

            return true;
        }

        // Above chrono threshold.
        if (!$belowChronoThreshold) {
            // Schedule function to be called recursively.
            async(function () {
                delay(self::RETRY_DELAY);

                $this->tryDisengageThrottle();
            });
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
     * Measures Future throughput in Futures/second.
     *
     * @return int Futures per second.
     */
    public function measureThroughput(): int
    {
        $this->removeObsoleteChronoEntries();

        return \count($this->chronoStack);
    }

    /**
     * Gets the maximum number of concurrent Futures that can be awaiting and unresolved.
     *
     * @return int Maximum number of concurrent Futures.
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * Sets the maximum number of concurrent Futures that can be awaiting and unresolved.
     *
     * @param int $maxConcurrency Maximum number of concurrent Futures.
     */
    public function setMaxConcurrency(int $maxConcurrency): void
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * Gets the maximum number of Futures that can be awaited per second.
     *
     * @return float Maximum number of Futures per second.
     */
    public function getMaxPerSecond(): float
    {
        return $this->maxPerSecond;
    }

    /**
     * Sets the maximum number of Futures that can be awaited per second.
     *
     * @param float $maxPerSecond Maximum number of Futures per second.
     */
    public function setMaxPerSecond(float $maxPerSecond): void
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
