<?php
declare(strict_types=1);

namespace ScriptFUSIONTest\Async\Throttle;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Success;
use ScriptFUSION\Async\Throttle\NullThrottle;

/**
 * @see NullThrottle
 */
final class NullThrottleTest extends AsyncTestCase
{
    /** @var NullThrottle */
    private $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttle = new NullThrottle;
    }

    public function testAwait(): \Generator
    {
        self::assertNull(yield $this->throttle->await(new Success()));
    }

    public function testJoin(): \Generator
    {
        self::assertTrue(yield $this->throttle->join());
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
