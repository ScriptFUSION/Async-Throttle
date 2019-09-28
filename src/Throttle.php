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
     * List of promises we're throttling.
     *
     * @var Promise[]
     */
    private $watching = [];

    /**
     * List of promises waiting to be notified when the throttle is cleared.
     *
     * @var Deferred[]
     */
    private $awaiting = [];

    private $maxConcurrency;

    private $maxPerSecond;

    private $total = 0;

    private $startTime;

    private $finished = false;

    public function __construct(int $maxPerSecond = 75, int $maxConcurrency = 30)
    {
        $this->maxPerSecond = $maxPerSecond;
        $this->maxConcurrency = $maxConcurrency;
    }

    public function await(Promise $promise): Promise
    {
        if ($this->finished) {
            throw new \BadMethodCallException('Cannot await: throttle has finished.');
        }

        $this->watch($promise);

        if ($this->tryFulfilPromises()) {
            /* Give consumers a chance to process the result before queuing another. Returning Success here forces a
             * throttle condition to be reached before any processing can be done by the caller on the results. */
            return new Delayed(0);
        }

        $deferred = new Deferred;
        $this->awaiting[spl_object_hash($deferred)] = $deferred;

        return $deferred->promise();
    }

    /**
     * Finish awaiting objects and waits for all pending promises to complete.
     *
     * @return Promise
     */
    public function finish(): Promise
    {
        $this->finished = true;

        return \Amp\call(function (): \Generator {
            yield $this->watching;
        });
    }

    private function watch(Promise $promise)/*: void*/
    {
        $this->startTime === null && $this->startTime = self::getTime();

        $this->watching[$hash = spl_object_hash($promise)] = $promise;

        $promise->onResolve(function () use ($hash)/*: void*/ {
            unset($this->watching[$hash]);

            $this->tryFulfilPromises();
        });

        ++$this->total;
    }

    private function isThrottled(): bool
    {
        return !$this->isBelowConcurrencyThreshold() || !$this->isBelowChronoThreshold();
    }

    private function isBelowConcurrencyThreshold(): bool
    {
        return $this->getActive() < $this->maxConcurrency;
    }

    private function isBelowChronoThreshold(): bool
    {
        return $this->total / max(1, self::getTime() - $this->startTime) <= $this->maxPerSecond;
    }

    private function tryFulfilPromises(): bool
    {
        if (!$this->isThrottled()) {
            foreach ($this->awaiting as $promise) {
                unset($this->awaiting[spl_object_hash($promise)]);
                $promise->resolve();
            }

            return true;
        }

        if (!$this->isBelowChronoThreshold()) {
            Loop::delay(
                self::RETRY_DELAY,
                function ()/*: void*/ {
                    $this->tryFulfilPromises();
                }
            );
        }

        return false;
    }

    public function getActive(): int
    {
        return \count($this->watching);
    }

    public function getTotal(): int
    {
        return $this->total;
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
