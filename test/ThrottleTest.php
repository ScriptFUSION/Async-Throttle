<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\Delayed;
use Amp\Loop;
use PHPUnit\Framework\TestCase;
use ScriptFUSION\Async\Throttle\Throttle;

/**
 * @see Throttle
 */
final class ThrottleTest extends TestCase
{
    /**
     * @var Throttle
     */
    private $throttle;

    protected function setUp()
    {
        $this->throttle = new Throttle;
    }

    /**
     * Tests that a single promise is resolved almost instantly.
     */
    public function testPromiseResolved(): void
    {
        Loop::run(function (): \Generator {
            $promise = new Delayed(0);

            $start = microtime(true);
            yield $this->throttle->await($promise);
            yield $this->throttle->finish();
            self::assertLessThanOrEqual(.01, microtime(true) - $start);

            $promise->onResolve(static function () use (&$resolved): void {
                $resolved = true;
            });
            self::assertTrue($resolved);
        });
    }

    /**
     * Tests that a hundred promises that resolve almost immediately are not throttled despite low concurrency limit.
     */
    public function testThroughput(): void
    {
        Loop::run(function (): \Generator {
            $this->throttle->setMaxConcurrency(1);
            $this->throttle->setMaxPerSecond($max = 100);

            $start = microtime(true);
            for ($i = 0; $i < $max; ++$i) {
                yield $this->throttle->await(new Delayed(0));
            }
            yield $this->throttle->finish();

            self::assertLessThanOrEqual(.1, microtime(true) - $start);
        });
    }
}
