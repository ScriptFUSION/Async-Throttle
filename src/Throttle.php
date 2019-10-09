<?php
declare(strict_types=1);

namespace ScriptFUSION\Async\Throttle;

use Amp\Deferred;
use Amp\Delayed;
use Amp\Loop;
use Amp\Promise;

class Throttle
{
    /**
     * Milliseconds to wait when the watch frequency crosses the threshold.
     */
    /*private*/ const RETRY_DELAY = 100;

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

    private $chronoStack = [];

    public function __construct(int $maxPerSecond = 75, int $maxConcurrency = 30)
    {
        $this->maxPerSecond = $maxPerSecond;
        $this->maxConcurrency = $maxConcurrency;
    }

    public function await(Promise $promise): Promise
    {
        if ($this->isThrottling()) {
            throw new \BadMethodCallException('Cannot await: throttle is engaged!');
        }

        $this->watch($promise);

        if ($this->tryFulfilPromises()) {
            /* Give consumers a chance to process the result before queuing another. Returning Success here forces a
             * throttle condition to be reached before any processing can be done by the caller on the results. */
            return new Delayed(0);
        }

        $this->throttle = new Deferred;

        return $this->throttle->promise();
    }

    private function watch(Promise $promise)/*: void*/
    {
        $this->chronoStack[] = self::getTime();

        $this->awaiting[$hash = spl_object_hash($promise)] = $promise;

        $promise->onResolve(function () use ($hash)/*: void*/ {
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

    private function discardObsoleteChronoEntries()/*: void*/
    {
        while (isset($this->chronoStack[0]) && $this->chronoStack[0] < self::getTime() - 1) {
            array_shift($this->chronoStack);
        }
    }

    private function tryFulfilPromises(): bool
    {
        // Not throttled.
        if ($this->isBelowConcurrencyThreshold() && $belowChronoThreshold = $this->isBelowChronoThreshold()) {
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
                function ()/*: void*/ {
                    $this->tryFulfilPromises();
                }
            );
        }

        return false;
    }

    /**
     * @return Promise[]
     */
    public function getAwaiting(): array
    {
        return $this->awaiting;
    }

    public function countAwaiting(): int
    {
        return \count($this->awaiting);
    }

    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    public function setMaxConcurrency(int $maxConcurrency)/*: void*/
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    public function getMaxPerSecond(): int
    {
        return $this->maxPerSecond;
    }

    public function setMaxPerSecond(int $maxPerSecond)/*: void*/
    {
        $this->maxPerSecond = $maxPerSecond;
    }

    private static function getTime(): float
    {
        return microtime(true);
    }
}
