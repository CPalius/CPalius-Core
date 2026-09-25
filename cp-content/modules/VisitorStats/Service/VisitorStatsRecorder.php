<?php

declare(strict_types=1);

namespace Modules\VisitorStats\Service;

use App\Core\Analytics\VisitorRecorderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Counts a page view without logging it as a row.
 *
 * TelemetrySubscriber used to write every page view into cp_system_telemetry_logs,
 * the same table security threat events live in — one row per hit, kept
 * alongside rows that exist to be inspected individually. This records the
 * same information as three small running totals instead: today's counters,
 * this hour's counter, and (only to tell a repeat visit from a first one
 * today) one row per distinct IP per day.
 */
final class VisitorStatsRecorder implements VisitorRecorderInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function record(string $ipAddress): void
    {
        try {
            $now = new \DateTimeImmutable();
            $date = $now->format('Y-m-d');
            $hour = (int) $now->format('G');
            $ip = mb_substr($ipAddress, 0, 45, 'UTF-8');

            $isFirstToday = (bool) $this->connection->executeStatement(
                'INSERT IGNORE INTO cp_visitor_daily_seen_ips (stat_date, ip_address) VALUES (:date, :ip)',
                ['date' => $date, 'ip' => $ip],
            );

            $this->connection->executeStatement(
                'INSERT INTO cp_visitor_daily_stats (stat_date, total_views, unique_visitors)
                 VALUES (:date, 1, :isFirst)
                 ON DUPLICATE KEY UPDATE total_views = total_views + 1, unique_visitors = unique_visitors + :isFirst',
                ['date' => $date, 'isFirst' => $isFirstToday ? 1 : 0],
            );

            $this->connection->executeStatement(
                'INSERT INTO cp_visitor_hourly_stats (stat_date, stat_hour, views)
                 VALUES (:date, :hour, 1)
                 ON DUPLICATE KEY UPDATE views = views + 1',
                ['date' => $date, 'hour' => $hour],
            );
        } catch (DBALException) {
            // Visitor counting is observability, not a request dependency.
        }
    }
}
