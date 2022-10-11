<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use ScriptFUSION\Async\Throttle\DualThrottle;
use ScriptFUSION\Async\Throttle\ThrottleOverloadException;
use function Amp\async;
use function Amp\delay;

/**
 * @see DualThrottle
 */
final class DualThrottleTest extends TestCase
{
    private DualThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttle = new DualThrottle;
    }

    /**
     * Tests that when concurrency is limited to one, each Future resolves in serial.
     */
    public function testConcurrency(): void
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $start = microtime(true);
        for ($i = 1; $i <= 4; ++$i) {
            $this->throttle->watch(async(delay(...), $delay = .25))->await();

            self::assertGreaterThan($lowerBound = $delay * $i, $time = microtime(true) - $start);
            self::assertLessThan($lowerBound + .05, $time);
        }

        self::assertTrue(isset($time), 'Looped.');
    }

    /**
     * Tests that 99 Futures that resolve immediately are not throttled despite low concurrency limit.
     */
    public function testThroughput(): void
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond($max = 100);

        $start = microtime(true);
        for ($i = 0; $i < $max - 1; ++$i) {
            $this->throttle->watch(Future::complete())->await();
        }

        self::assertLessThanOrEqual(.02, microtime(true) - $start);
    }

    /**
     * Tests that 100 Futures that resolve immediately are throttled only once the chrono threshold is hit.
     */
    public function testThroughput2(): void
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond($max = 100);

        $start = microtime(true);
        for ($i = 0; $i < $max; ++$i) {
            $this->throttle->watch(Future::complete())->await();
        }

        self::assertGreaterThan(1, $time = microtime(true) - $start);
        self::assertLessThan(2, $time);
    }

    /**
     * Tests that when concurrency is unbounded and the throughput is 1/sec, the specified number of Futures,
     * each resolving immediately, are throttled with one-second delays between each.
     *
     * @param int $futures Number of Futures.
     *
     * @dataProvider provideFutureAmount
     */
    public function testNFuturesThrottled(int $futures): void
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1);

        $start = microtime(true);
        for ($i = 0; $i < $futures; ++$i) {
            $this->throttle->watch(Future::complete())->await();
        }

        self::assertGreaterThan($futures, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($futures + 1, $time, 'Maximum execution time.');
    }

    public function provideFutureAmount(): iterable
    {
        yield 'One Future' => [1];
        yield 'Two Futures' => [2];
        yield 'Three Futures' => [3];
    }

    /**
     * Tests that when concurrency is unbounded and the throughput is 1 per 2 seconds, the specified number of
     * Futures, each resolving immediately, are throttled with two-second delays between each.
     *
     * @param int $futures Number of Futures.
     *
     * @dataProvider provideFutureAmount
     */
    public function testSlowThrottle(int $futures): void
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1 / 2);

        $start = microtime(true);
        for ($i = 0; $i < $futures; ++$i) {
            $this->throttle->watch(Future::complete())->await();
        }

        self::assertGreaterThan($futures * 2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($futures * 2 + 1, $time, 'Maximum execution time.');
    }

    /**
     * Tests that when throttle->watch() is not awaited and the throttle is engaged, an exception is thrown.
     */
    public function testThrottleAbuse(): void
    {
        $this->throttle->setMaxPerSecond(1);

        // Throttle is not engaged.
        $this->throttle->watch(Future::complete())->await();
        self::assertFalse($this->throttle->isThrottling());

        // Throttle is now engaged.
        $this->throttle->watch(Future::complete());
        self::assertTrue($this->throttle->isThrottling());

        // Throttle is still engaged, but we didn't await the last watch() operation.
        $this->expectException(ThrottleOverloadException::class);
        $this->throttle->watch(Future::complete());
    }

    /**
     * Tests that when a burst of Futures arrive at once, they are throttled according to the chrono limit.
     */
    public function testBurst(): void
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1);

        // Engage throttle.
        $this->throttle->watch(Future::complete());
        self::assertTrue($this->throttle->isThrottling());

        // Wait 3 seconds.
        delay(3);

        $start = microtime(true);

        // First must be throttled despite last two seconds having no activity.
        $this->throttle->watch(Future::complete())->await();

        // Second must be throttled for one second.
        $this->throttle->watch(Future::complete())->await();

        self::assertGreaterThan(2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan(3, $time, 'Maximum execution time.');
    }

    /**
     * Tests that incomplete Futures can be retrieved from the throttle and awaited.
     */
    public function testWatching(): void
    {
        $this->throttle->setMaxConcurrency(3);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $start = microtime(true);
        $this->throttle->watch($f1 = async(delay(...), .1));
        $this->throttle->watch($f2 = async(delay(...), .2));
        $this->throttle->watch($f3 = async(delay(...), $longest = .3));

        self::assertCount(3, $awaiting = $this->throttle->getWatched());
        self::assertSame(3, $this->throttle->countWatched());
        self::assertContains($f1, $awaiting, 'Future #1.');
        self::assertContains($f2, $awaiting, 'Future #2.');
        self::assertContains($f3, $awaiting, 'Future #3.');

        Future\await($awaiting);
        self::assertGreaterThan($longest, microtime(true) - $start);
    }

    /**
     * Tests that the watching counter outputs correct values during throttle spin up and spin down.
     */
    public function testCountWatched(): void
    {
        $this->throttle->setMaxConcurrency($max = 10);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $countUp = $countDown = [];

        // Spin up.
        for ($count = 0; $count < $max; ++$count) {
            $this->throttle->watch(async(
                function () use ($count, $max, &$countDown): void {
                    delay($count);

                    // Spin down.
                    self::assertSame(
                        $max - $count,
                        $countDown[] = $this->throttle->countWatched(),
                        "Count down iteration #$count."
                    );
                }
            ));

            self::assertSame($count + 1, $countUp[] = $this->throttle->countWatched(), "Count up iteration #$count.");
        }

        self::assertSame($max, $this->throttle->countWatched(), 'Fully loaded.');
        Future\await($this->throttle->getWatched());
        self::assertSame(0, $this->throttle->countWatched(), 'Completely emptied.');

        self::assertSame(range(1, $max), $countUp, 'Count up.');
        self::assertSame(range($max, 1), $countDown, 'Count down.');
    }

    /**
     * Tests that when throttling, calling join() will wait until the throttle is cleared before continuing.
     */
    public function testJoin(): void
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->watch(Future::complete());
        self::assertTrue($this->throttle->isThrottling(), 'Throttle is throttling.');

        try {
            $this->throttle->watch(Future::complete());
        } catch (ThrottleOverloadException $exception) {
        }
        self::assertTrue(isset($exception), 'Exception thrown trying to watch another Future.');

        self::assertFalse($this->throttle->join()?->await(), 'Free slot unconfirmed.');
        self::assertFalse($this->throttle->isThrottling(), 'Throttle is not actually throttling.');
        self::assertNull($this->throttle->join()?->await(), 'Free slot confirmed.');

        $this->throttle->watch(Future::complete());
    }

    /**
     * Tests that when multiple fibers are started at the same time, calling join() in a loop will prevent them from
     * overloading the throttle.
     */
    public function testJoinMultiFiber(): void
    {
        $this->throttle->setMaxConcurrency(1);

        $fiber = function () {
            while (!($this->throttle->join()?->await() ?? true)) {
                // Wait for free slot.
            }

            $this->throttle->watch(async(delay(...), 0))->await();
        };

        Future\await([async($fiber), async($fiber), async($fiber)]);

        // ThrottleOverloadException is not thrown.
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that getters return the same values passed to setters.
     */
    public function testSetterRoundTrip(): void
    {
        $this->throttle->setMaxConcurrency($concurrency = 123);
        self::assertSame($concurrency, $this->throttle->getMaxConcurrency());

        $this->throttle->setMaxPerSecond($chrono = 456.);
        self::assertSame($chrono, $this->throttle->getMaxPerSecond());
    }
}
