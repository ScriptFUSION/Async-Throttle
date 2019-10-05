<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

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
     * Tests that even when throttle constraints are at their lowest values, a single promise is not throttled.
     */
    public function testPromiseResolved(): \Generator
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond(1);

        $start = microtime(true);
        yield $this->throttle->await(new Success());

        self::assertLessThanOrEqual(.01, microtime(true) - $start);
    }

    /**
     * Tests that a hundred promises that resolve immediately are not throttled despite low concurrency limit.
     */
    public function testThroughput(): \Generator
    {
        $this->throttle->setMaxConcurrency(1);
        $this->throttle->setMaxPerSecond($max = 100);

        $start = microtime(true);
        for ($i = 0; $i < $max; ++$i) {
            yield $this->throttle->await(new Success());
        }

        self::assertLessThanOrEqual(.1, microtime(true) - $start);
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

        self::assertGreaterThan($promises - 1, $time = microtime(true) - $start, 'Minimum execution time.');
        self::assertLessThan($promises, $time, 'Maximum execution time.');
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
}
