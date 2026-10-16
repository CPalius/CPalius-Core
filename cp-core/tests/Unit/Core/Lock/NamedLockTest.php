<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Lock;

use App\Core\Lock\LockTimeoutException;
use App\Core\Lock\NamedLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedLock::class)]
final class NamedLockTest extends TestCase
{
    public function testRunsTheWorkBetweenAcquireAndRelease(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$calls): int {
            $calls[] = str_contains($sql, 'GET_LOCK') ? 'acquire' : 'release';

            return 1;
        });

        $result = (new NamedLock($connection))->synchronized('tour:42', static function () use (&$calls): string {
            $calls[] = 'work';

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['acquire', 'work', 'release'], $calls);
    }

    public function testThrowsWhenTheLockStaysBusyAndNeverRunsTheWork(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturn(0);

        $ran = false;

        try {
            (new NamedLock($connection))->synchronized('tour:42', static function () use (&$ran): void {
                $ran = true;
            }, 1);
            self::fail('Expected a LockTimeoutException.');
        } catch (LockTimeoutException $e) {
            self::assertSame('tour:42', $e->lockName);
        }

        self::assertFalse($ran);
    }

    public function testReleasesTheLockWhenTheWorkThrows(): void
    {
        $released = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$released): int {
            $released = $released || str_contains($sql, 'RELEASE_LOCK');

            return 1;
        });

        try {
            (new NamedLock($connection))->synchronized('x', static function (): void {
                throw new \LogicException('boom');
            });
            self::fail('Expected the work exception.');
        } catch (\LogicException) {
        }

        self::assertTrue($released);
    }

    public function testNameIsHashedToStayWithinTheMysqlLimit(): void
    {
        $key = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use (&$key): int {
            $key ??= $params[0];

            return 1;
        });

        (new NamedLock($connection))->synchronized(str_repeat('very-long-name-', 20), static fn () => null);

        self::assertLessThanOrEqual(64, \strlen((string) $key));
    }

    public function testOtherPlatformsJustRunTheWork(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('fetchOne');

        self::assertSame(7, (new NamedLock($connection))->synchronized('x', static fn (): int => 7));
    }
}
