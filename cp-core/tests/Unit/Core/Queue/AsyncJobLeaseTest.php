<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Queue;

use App\Core\Queue\AsyncJobLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Two workers read the same due job; only one may win it.
 */
#[CoversClass(AsyncJobLease::class)]
final class AsyncJobLeaseTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE cp_async_jobs (id INTEGER PRIMARY KEY, available_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL)');
        $this->connection->executeStatement("INSERT INTO cp_async_jobs (id, available_at) VALUES (1, '2026-01-01 10:00:00')");
    }

    public function testOnlyTheFirstWorkerWinsTheJob(): void
    {
        $seen = new \DateTimeImmutable('2026-01-01 10:00:00');
        $now = new \DateTimeImmutable('2026-01-01 10:05:00');

        self::assertTrue(AsyncJobLease::tryClaim($this->connection, 1, $seen, $now));
        // The second worker still holds the value it read before the first one moved it.
        self::assertFalse(AsyncJobLease::tryClaim($this->connection, 1, $seen, $now));
    }

    public function testTheLeaseMovesAvailableAtForward(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:05:00');
        AsyncJobLease::tryClaim($this->connection, 1, new \DateTimeImmutable('2026-01-01 10:00:00'), $now);

        $until = (new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT available_at FROM cp_async_jobs WHERE id = 1')));

        self::assertSame($now->modify('+'.AsyncJobLease::SECONDS.' seconds')->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'));
    }

    public function testAFinishedJobCannotBeClaimed(): void
    {
        $this->connection->executeStatement("UPDATE cp_async_jobs SET processed_at = '2026-01-01 10:01:00'");

        self::assertFalse(AsyncJobLease::tryClaim($this->connection, 1, new \DateTimeImmutable('2026-01-01 10:00:00'), new \DateTimeImmutable('2026-01-01 10:05:00')));
    }

    public function testAnExpiredLeaseMakesTheJobClaimableAgain(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:05:00');
        AsyncJobLease::tryClaim($this->connection, 1, new \DateTimeImmutable('2026-01-01 10:00:00'), $now);

        $leased = $now->modify('+'.AsyncJobLease::SECONDS.' seconds');
        $later = $leased->modify('+1 second');

        self::assertTrue(AsyncJobLease::tryClaim($this->connection, 1, $leased, $later));
    }
}
