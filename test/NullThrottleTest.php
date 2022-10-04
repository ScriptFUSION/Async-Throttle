<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use ScriptFUSION\Async\Throttle\NullThrottle;

/**
 * @see NullThrottle
 */
final class NullThrottleTest extends TestCase
{
    private NullThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttle = new NullThrottle;
    }

    public function testAwait(): void
    {
        self::assertNull($this->throttle->await(Future::complete())->await());
    }

    public function testJoin(): void
    {
        self::assertTrue($this->throttle->join()->await());
    }

    public function testIsThrottling(): void
    {
        self::assertFalse($this->throttle->isThrottling());
    }

    public function testGetAwaiting(): void
    {
        self::assertEmpty($this->throttle->getAwaiting());
    }

    public function testCountAwaiting(): void
    {
        self::assertSame(0, $this->throttle->countAwaiting());
    }
}
