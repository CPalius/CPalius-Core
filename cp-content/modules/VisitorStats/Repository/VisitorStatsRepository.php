<?php

declare(strict_types=1);

namespace Modules\VisitorStats\Repository;

use App\Core\Analytics\VisitorStatsProviderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Read side of the aggregated visitor counters VisitorStatsRecorder writes.
 */
final class VisitorStatsRepository implements VisitorStatsProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Page views/unique visitors are "today" (calendar day), not a rolling
     * window: cp_visitor_daily_seen_ips dedupes per calendar day, so an exact
     * rolling-24h unique count would need per-visit timestamps again — the
     * exact thing this table exists to avoid keeping. The hourly trend below
     * has no such constraint and stays a genuine rolling window.
     *
     * @return array{uniqueIps: int, pageViews: int, hourly: array{labels: list<string>, hits: list<int>}}
     */
    public function stats(int $hours = 24): array
    {
        [$uniqueVisitors, $totalViews] = $this->todayTotals();

        return [
            'uniqueIps' => $uniqueVisitors,
            'pageViews' => $totalViews,
            'hourly' => $this->hourly($hours),
        ];
    }

    /**
     * Daily history for the module's own admin page — the reason
     * cp_visitor_daily_stats is never pruned (~365 rows/year). Oldest first,
     * so a day-by-day chart reads left to right; every day in the window is
     * present even with zero visits (a day with no row is not "missing", it
     * had none), which a plain SELECT would silently skip.
     *
     * @return list<array{date: string, totalViews: int, uniqueVisitors: int}>
     */
    public function dailyHistory(int $days = 30): array
    {
        $today = new \DateTimeImmutable('today');

        try {
            $since = $today->modify(sprintf('-%d days', $days - 1))->format('Y-m-d');
            $rows = $this->connection->fetchAllAssociative(
                'SELECT stat_date, total_views, unique_visitors FROM cp_visitor_daily_stats
                 WHERE stat_date >= :since ORDER BY stat_date ASC',
                ['since' => $since],
            );
        } catch (DBALException) {
            $rows = [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['stat_date']] = [
                'totalViews' => (int) $row['total_views'],
                'uniqueVisitors' => (int) $row['unique_visitors'],
            ];
        }

        $history = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $date = $today->modify(sprintf('-%d days', $i))->format('Y-m-d');
            $history[] = [
                'date' => $date,
                'totalViews' => $byDate[$date]['totalViews'] ?? 0,
                'uniqueVisitors' => $byDate[$date]['uniqueVisitors'] ?? 0,
            ];
        }

        return $history;
    }

    /**
     * Monthly totals for the last N months, summed from the same daily rows
     * — no separate table, exactly the aggregation this method name says.
     * Oldest first, current (partial) month included and labelled as such
     * by the caller if it wants to.
     *
     * @return list<array{month: string, totalViews: int, uniqueVisitors: int}>
     */
    public function monthlyHistory(int $months = 12): array
    {
        $firstOfThisMonth = new \DateTimeImmutable('first day of this month');

        try {
            $since = $firstOfThisMonth->modify(sprintf('-%d months', $months - 1))->format('Y-m-d');
            $rows = $this->connection->fetchAllAssociative(
                "SELECT DATE_FORMAT(stat_date, '%Y-%m') AS ym,
                        SUM(total_views) AS total_views,
                        SUM(unique_visitors) AS unique_visitors
                 FROM cp_visitor_daily_stats
                 WHERE stat_date >= :since
                 GROUP BY ym",
                ['since' => $since],
            );
        } catch (DBALException) {
            $rows = [];
        }

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = [
                'totalViews' => (int) $row['total_views'],
                'uniqueVisitors' => (int) $row['unique_visitors'],
            ];
        }

        $history = [];
        for ($i = $months - 1; $i >= 0; --$i) {
            $ym = $firstOfThisMonth->modify(sprintf('-%d months', $i))->format('Y-m');
            $history[] = [
                'month' => $ym,
                'totalViews' => $byMonth[$ym]['totalViews'] ?? 0,
                'uniqueVisitors' => $byMonth[$ym]['uniqueVisitors'] ?? 0,
            ];
        }

        return $history;
    }

    /**
     * @return array{0: int, 1: int} [uniqueVisitors, totalViews]
     */
    private function todayTotals(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT total_views, unique_visitors FROM cp_visitor_daily_stats WHERE stat_date = :date',
                ['date' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
            );
        } catch (DBALException) {
            return [0, 0];
        }

        if ($row === false) {
            return [0, 0];
        }

        return [(int) $row['unique_visitors'], (int) $row['total_views']];
    }

    /**
     * @return array{labels: list<string>, hits: list<int>}
     */
    private function hourly(int $hours): array
    {
        $now = new \DateTimeImmutable();
        $labels = [];
        $keys = [];

        for ($i = $hours - 1; $i >= 0; --$i) {
            $bucket = $now->modify(sprintf('-%d hours', $i));
            $keys[] = $this->bucketKey($bucket->format('Y-m-d'), (int) $bucket->format('G'));
            $labels[] = $bucket->format('H:00');
        }

        $earliestDate = $now->modify(sprintf('-%d hours', $hours - 1))->format('Y-m-d');

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT stat_date, stat_hour, views FROM cp_visitor_hourly_stats WHERE stat_date >= :earliest',
                ['earliest' => $earliestDate],
            );
        } catch (DBALException) {
            $rows = [];
        }

        $index = [];
        foreach ($rows as $row) {
            $index[$this->bucketKey((string) $row['stat_date'], (int) $row['stat_hour'])] = (int) $row['views'];
        }

        $hits = [];
        foreach ($keys as $key) {
            $hits[] = $index[$key] ?? 0;
        }

        return ['labels' => $labels, 'hits' => $hits];
    }

    private function bucketKey(string $date, int $hour): string
    {
        return $date.'|'.$hour;
    }
}
