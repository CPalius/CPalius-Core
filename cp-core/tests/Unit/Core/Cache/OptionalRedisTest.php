<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Cache;

use App\Core\Cache\OptionalRedis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Law 2.3: no path here may throw — a missing extension, an empty DSN or a dead
 * server is always reported as "null / not available", never an exception.
 */
#[CoversClass(OptionalRedis::class)]
final class OptionalRedisTest extends TestCase
{
    public function testEmptyDsnIsNullAndUnavailable(): void
    {
        $redis = new OptionalRedis('   ');

        self::assertNull($redis->get());
        self::assertFalse($redis->isAvailable());
    }

    public function testUnreachableServerDegradesToNullWithoutThrowing(): void
    {
        // Port 6390 is effectively always closed; connect must fail fast and quietly.
        $redis = new OptionalRedis('redis://127.0.0.1:6390?timeout=0.05');

        self::assertNull($redis->get());
        self::assertFalse($redis->isAvailable());
    }

    public function testMalformedDsnNeverThrows(): void
    {
        $redis = new OptionalRedis('not-a-valid-dsn://::::');

        // The contract is "no exception escapes", whatever get() decides to return.
        $redis->get();
        self::assertFalse($redis->isAvailable());
    }

    public function testGetIsMemoised(): void
    {
        $redis = new OptionalRedis('redis://127.0.0.1:6390?timeout=0.05');

        self::assertSame($redis->get(), $redis->get());
    }
}
