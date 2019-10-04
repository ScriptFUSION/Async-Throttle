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
     * Tests that a single promise is not throttled.
     */
    public function testPromiseResolved(): \Generator
    {
        $promise = new Success();

        $start = microtime(true);
        yield $this->throttle->await($promise);
        self::assertLessThanOrEqual(.01, microtime(true) - $start);

        $promise->onResolve(static function () use (&$resolved)/*: void*/ {
            $resolved = true;
        });

        self::assertTrue($resolved);
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
}
