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
            $this->throttle->await(async(delay(...), $delay = .25))->await();

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
            $this->throttle->await(Future::complete())->await();
        }

        self::assertLessThanOrEqual(.01, microtime(true) - $start);
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
            $this->throttle->await(Future::complete())->await();
        }

        self::assertGreaterThan(1, microtime(true) - $start);
        self::assertLessThan(2, microtime(true) - $start);
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
            $this->throttle->await(Future::complete())->await();
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
            $this->throttle->await(Future::complete())->await();
        }

        self::assertGreaterThan($futures * 2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($futures * 2 + 1, $time, 'Maximum execution time.');
    }

    /**
     * Tests that when throttle->await() is not yielded and the throttle is engaged, an exception is thrown.
     */
    public function testThrottleAbuse(): void
    {
        $this->throttle->setMaxPerSecond(1);

        // Throttle is not engaged.
        $this->throttle->await(Future::complete())->await();
        self::assertFalse($this->throttle->isThrottling());

        // Throttle is now engaged.
        $this->throttle->await(Future::complete());
        self::assertTrue($this->throttle->isThrottling());

        // Throttle is still engaged, but we didn't yield the last await() operation.
        $this->expectException(ThrottleOverloadException::class);
        $this->throttle->await(Future::complete());
    }

    /**
     * Tests that when a burst of Futures arrive at once, they are throttled according to the chrono limit.
     */
    public function testBurst(): void
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1);

        // Engage throttle.
        $this->throttle->await(Future::complete());
        self::assertTrue($this->throttle->isThrottling());

        // Wait 3 seconds.
        delay(3);

        $start = microtime(true);

        // First must be throttled despite last two seconds having no activity.
        $this->throttle->await(Future::complete())->await();

        // Second must be throttled for one second.
        $this->throttle->await(Future::complete())->await();

        self::assertGreaterThan(2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan(3, $time, 'Maximum execution time.');
    }

    /**
     * Tests that Futures awaiting (not yet resolved) can be retrieved from the throttle and yielded.
     */
    public function testAwaiting(): void
    {
        $this->throttle->setMaxConcurrency(3);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $start = microtime(true);
        $this->throttle->await($p1 = async(delay(...), .1));
        $this->throttle->await($p2 = async(delay(...), .2));
        $this->throttle->await($p3 = async(delay(...), $longest = .3));

        // Retrieve.
        $awaiting = $this->throttle->getAwaiting();
        self::assertContains($p1, $awaiting, 'Future #1.');
        self::assertContains($p2, $awaiting, 'Future #2.');
        self::assertContains($p3, $awaiting, 'Future #3.');

        // Yield.
        Future\await($awaiting);
        self::assertGreaterThan($longest, microtime(true) - $start);
    }

    /**
     * Tests that the awaiting counter outputs correct values during throttle spin up and spin down.
     */
    public function testCountAwaiting(): void
    {
        $this->throttle->setMaxConcurrency($max = 10);
        $this->throttle->setMaxPerSecond(PHP_INT_MAX);

        $countUp = $countDown = [];

        // Spin up.
        for ($count = 0; $count < $max; ++$count) {
            $this->throttle->await(
                async(delay(...), $count)->map(
                    // Spin down.
                    function () use ($count, $max, &$countDown): void {
                        self::assertSame(
                            $max - $count,
                            $countDown[] = $this->throttle->countAwaiting(),
                            "Count down iteration #$count."
                        );
                    }
                )
            );

            self::assertSame($count + 1, $countUp[] = $this->throttle->countAwaiting(), "Count up iteration #$count.");
        }

        self::assertSame($max, $this->throttle->countAwaiting(), 'Fully loaded.');
        Future\await($this->throttle->getAwaiting());
        self::assertSame(0, $this->throttle->countAwaiting(), 'Completely emptied.');

        self::assertSame(range(1, $max), $countUp, 'Count up.');
        self::assertSame(range($max, 1), $countDown, 'Count down.');
    }

    /**
     * Tests that when throttling, calling join() will wait until the throttle is cleared before continuing.
     */
    public function testJoin(): void
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->await(Future::complete());
        self::assertTrue($this->throttle->isThrottling(), 'Throttle is throttling.');

        try {
            $this->throttle->await(Future::complete());
        } catch (ThrottleOverloadException $exception) {
        }
        self::assertTrue(isset($exception), 'Exception thrown trying to await another Future.');

        self::assertFalse($this->throttle->join()->await(), 'Free slot unconfirmed.');
        self::assertFalse($this->throttle->isThrottling(), 'Throttle is not actually throttling.');
        self::assertTrue($this->throttle->join()->await(), 'Free slot confirmed.');

        $this->throttle->await(Future::complete());
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
