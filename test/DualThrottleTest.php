<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use ScriptFUSION\Async\Throttle\DualThrottle;
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
            $this->throttle->async(delay(...), $delay = .25)->await();

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
            $this->throttle->async(fn () => null)->await();
        }

        self::assertLessThanOrEqual(.04, microtime(true) - $start);
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
            $this->throttle->async(fn () => null)->await();
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
            $this->throttle->async(fn () => null)->await();
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
            $this->throttle->async(fn () => null)->await();
        }

        self::assertGreaterThan($futures * 2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($futures * 2 + 1, $time, 'Maximum execution time.');
    }

    /**
     * Tests that when throttle->watch() is not awaited and the throttle is engaged, we get suspended.
     */
    public function testThrottleAbuse(): void
    {
        $this->throttle->setMaxPerSecond(1);
        $start = microtime(true);

        // Throttle is not engaged.
        $this->throttle->async(fn () => null)->await();
        self::assertFalse($this->throttle->isThrottling());

        // Throttle is now engaged because we don't await().
        $this->throttle->async(fn () => null);
        self::assertTrue($this->throttle->isThrottling());

        // Throttle is no longer engaged despite not awaiting previous because we at least await this time.
        $this->throttle->async(fn () => null)->await();
        self::assertFalse($this->throttle->isThrottling());

        // Total time is still 3 seconds because suspensions guarantee throttle behaviour despite abuse.
        self::assertGreaterThan(3, microtime(true) - $start);
    }

    /**
     * Tests that when a burst of Futures arrive at once, they are throttled according to the chrono limit.
     */
    public function testBurst(): void
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1);

        // Engage throttle.
        $this->throttle->async(fn () => null);
        self::assertTrue($this->throttle->isThrottling());

        // Wait 3 seconds.
        delay(3);

        $start = microtime(true);

        // First must be throttled despite last two seconds having no activity.
        $this->throttle->async(fn () => null)->await();

        // Second must be throttled for one second.
        $this->throttle->async(fn () => null)->await();

        self::assertGreaterThan(2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan(3, $time, 'Maximum execution time.');
    }

    /**
     * Tests that incomplete Futures can be retrieved from the throttle and awaited.
     */
    public function testWatching(): void
    {
        $this->throttle->setMaxConcurrency($max = 3);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $start = microtime(true);
        $this->throttle->async(delay(...), .1);
        $this->throttle->async(delay(...), .2);
        $this->throttle->async(delay(...), $longest = .3);

        self::assertCount($max, $awaiting = $this->throttle->getPending());
        self::assertSame($max, $this->throttle->countPending());

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
            $this->throttle->async(
                function () use ($count, $max, &$countDown): void {
                    delay($count);

                    // Spin down.
                    self::assertSame(
                        $max - $count,
                        $countDown[] = $this->throttle->countPending(),
                        "Count down iteration #$count."
                    );
                }
            );

            self::assertSame($count + 1, $countUp[] = $this->throttle->countPending(), "Count up iteration #$count.");
        }

        self::assertSame($max, $this->throttle->countPending(), 'Fully loaded.');
        Future\await($this->throttle->getPending());
        self::assertSame(0, $this->throttle->countPending(), 'Completely emptied.');

        self::assertSame(range(1, $max), $countUp, 'Count up.');
        self::assertSame(range($max, 1), $countDown, 'Count down.');
    }

    /**
     * Tests that when multiple fibers are started at the same time, suspensions will prevent them overloading the
     * throttle, and critically, each fiber starts at least one second apart.
     */
    public function testMultiFiberSuspensions(): void
    {
        $this->throttle->setMaxPerSecond(1);

        $fiber = fn () => $this->throttle->async(fn () => microtime(true))->await();

        $start = microtime(true);
        $time = Future\await([async($fiber), async($fiber), async($fiber)]);

        self::assertGreaterThan(3, microtime(true) - $start);
        // This assumes fiber processing order is deterministic, which it is, because suspensions are queued.
        self::assertLessThan(1, $time[0] - $start, 'First fiber is not throttled.');
        self::assertGreaterThan(1, $time[1] - $time[0], 'Second fiber is at least one second behind the first.');
        self::assertGreaterThan(1, $time[2] - $time[1], 'Third fiber is at least one second behind the second.');
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

    /**
     * Tests that when the unit of work throws an exception, the same exception is caught and no unhandled exceptions
     * occur in the underlying library.
     */
    public function testThrow(): void
    {
        $this->throttle->setMaxConcurrency(1);

        $this->expectExceptionObject($exception = new \Exception());
        $this->throttle->async(fn () => throw $exception)->await();
    }
}
