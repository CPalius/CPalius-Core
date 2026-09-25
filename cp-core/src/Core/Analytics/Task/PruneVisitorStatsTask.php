<?php

declare(strict_types=1);

namespace App\Core\Analytics\Task;

use App\Core\Cron\Attribute\CpCronJob;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Prunes the two support tables VisitorStatsRecorder writes alongside the
 * daily totals. cp_visitor_daily_stats (one tiny row per day) is never
 * pruned here — a year of it is ~365 rows.
 */
final class PruneVisitorStatsTask
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[CpCronJob(schedule: '15 3 * * *', name: 'visitor_stats.prune', description: 'Prune the hourly-trend and daily-uniqueness support tables behind visitor statistics')]
    public function execute(): string
    {
        $hourlyBefore = (new \DateTimeImmutable())->modify('-8 days')->format('Y-m-d');
        $seenIpsBefore = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d');

        try {
            $hourlyDeleted = (int) $this->connection->executeStatement(
                'DELETE FROM cp_visitor_hourly_stats WHERE stat_date < :before',
                ['before' => $hourlyBefore],
            );
            $seenIpsDeleted = (int) $this->connection->executeStatement(
                'DELETE FROM cp_visitor_daily_seen_ips WHERE stat_date < :before',
                ['before' => $seenIpsBefore],
            );
        } catch (DBALException $e) {
            return 'Visitor stats prune failed: '.$e->getMessage();
        }

        return sprintf(
            'Visitor stats pruned: %d hourly row(s) before %s, %d seen-IP row(s) before %s.',
            $hourlyDeleted,
            $hourlyBefore,
            $seenIpsDeleted,
            $seenIpsBefore,
        );
    }
}
