<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\Delayed;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Success;
use ScriptFUSION\Async\Throttle\Throttle;

/**
 * @see Throttle
 */
final class ThrottleTest extends AsyncTestCase
{
    /** @var Throttle */
    private $throttle;

    protected function setUp()/*: void*/
    {
        parent::setUp();

        $this->throttle = new Throttle;
    }

    /**
     * Tests that when throttle constraints are at their lowest values, a single promise is throttled for one second.
     */
    public function testPromiseResolved(): \Generator
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond(1);

        $start = microtime(true);
        yield $this->throttle->await(new Success());

        self::assertGreaterThan(1, $time = microtime(true) - $start);
        self::assertLessThan(2, $time);
    }

    /**
     * Tests that 99 promises that resolve immediately are not throttled despite low concurrency limit.
     */
    public function testThroughput(): \Generator
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond($max = 100);

        $start = microtime(true);
        for ($i = 0; $i < $max - 1; ++$i) {
            yield $this->throttle->await(new Success());
        }

        self::assertLessThanOrEqual(.1, microtime(true) - $start);
    }

    public function testThroughput2(): \Generator
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond($max = 100);

        $start = microtime(true);
        for ($i = 0; $i < $max; ++$i) {
            yield $this->throttle->await(new Success());
        }

        self::assertGreaterThan(1, microtime(true) - $start);
        self::assertLessThan(2, microtime(true) - $start);
    }

    /**
     * Tests that when concurrency is unbounded and the throughput is 1/sec, the specified number of promises,
     * each resolving immediately, are throttled with one second delays each, except the first.
     *
     * @param int $promises Number of promises.
     *
     * @dataProvider providePromiseAmount
     */
    public function testNPromisesThrottled(int $promises): \Generator
    {
        $this->throttle->setMaxConcurrency(PHP_INT_MAX);
        $this->throttle->setMaxPerSecond(1);

        $start = microtime(true);
        for ($i = 0; $i < $promises; ++$i) {
            yield $this->throttle->await(new Success());
        }

        self::assertGreaterThan($promises, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($promises + 1, $time, 'Maximum execution time.');
    }

    public function providePromiseAmount()/*: iterable*/
    {
        yield 'Two promises' => [2];
        yield 'Three promises' => [3];
    }

    /**
     * Tests that when throttle->await() is not yielded and the throttle is engaged, an exception is thrown.
     */
    public function testThrottleAbuse(): \Generator
    {
        $this->throttle->setMaxPerSecond(1);

        // Throttle is not engaged.
        yield $this->throttle->await(new Success());
        self::assertFalse($this->throttle->isThrottling());

        // Throttle is now engaged.
        $this->throttle->await(new Success());
        self::assertTrue($this->throttle->isThrottling());

        // Throttle is still engaged, but we didn't yield the last await() operation.
        $this->expectException(\BadMethodCallException::class);
        $this->throttle->await(new Success());
    }

    /**
     * Tests that when a burst of promises arrive at once, they are throttled according to the chrono limit.
     */
    public function testBurst(): \Generator
    {
        $this->throttle->setMaxPerSecond(1);

        // Engage throttle.
        $this->throttle->await(new Success());
        self::assertTrue($this->throttle->isThrottling());

        // Wait 3 seconds.
        yield new Delayed(3000);

        $start = microtime(true);

        // First must be throttled despite last two seconds having no activity.
        yield $this->throttle->await(new Success());

        // Second must be throttled for one second.
        yield $this->throttle->await(new Success());

        self::assertGreaterThan(2, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan(3, $time, 'Maximum execution time.');
        echo $time;
    }

    /**
     * Tests that getters return the same values passed to setters.
     */
    public function testSetterRoundTrip()/*: void*/
    {
        $this->throttle->setMaxConcurrency($concurrency = 123);
        self::assertSame($concurrency, $this->throttle->getMaxConcurrency());

        $this->throttle->setMaxPerSecond($chrono = 456);
        self::assertSame($chrono, $this->throttle->getMaxPerSecond());
    }
}
