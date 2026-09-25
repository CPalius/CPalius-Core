<?php

declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Entity\SystemTelemetryLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fast DBAL writes/reads for telemetry. ORM is only used for mapping.
 *
 * @extends ServiceEntityRepository<SystemTelemetryLog>
 */
class TelemetryLogRepository extends ServiceEntityRepository
{
    /** Per-value cap inside the details JSON, so one padded payload cannot bloat the row. */
    private const DETAIL_VALUE_LIMIT = 1000;

    /** Keys come from attacker-controlled parameter names; keep them short too. */
    private const DETAIL_KEY_LIMIT = 190;

    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct($registry, SystemTelemetryLog::class);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function insertRow(
        string $ipAddress,
        ?int $userId,
        string $requestMethod,
        string $requestUri,
        string $userAgent,
        string $severity,
        string $eventType,
        int $threatScore,
        array $details,
    ): void {
        $this->connection->insert('cp_system_telemetry_logs', [
            'ip_address' => self::cleanText($ipAddress, 45),
            'user_id' => $userId,
            'request_method' => self::cleanText(strtoupper($requestMethod), 10),
            'request_uri' => self::cleanText($requestUri, 1000),
            'user_agent' => self::cleanText($userAgent, 500),
            'severity' => $severity,
            'event_type' => $eventType,
            'threat_score' => max(0, min(100, $threatScore)),
            'details' => self::cleanJson($details),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'user_id' => Types::BIGINT,
            'threat_score' => Types::INTEGER,
            'details' => Types::JSON,
        ]);
    }

    /**
     * Telemetry records exactly the traffic that is least likely to be well formed:
     * scanners send raw bytes, over-long encodings and NUL padding. Those break both
     * json_encode() on the details column and the utf8mb4 text columns, and the whole
     * row was lost. Scrub at the write boundary so a malformed request still gets logged.
     */
    private static function cleanText(string $value, int $limit): string
    {
        $value = str_replace("\0", '', mb_scrub($value, 'UTF-8'));

        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<array-key, mixed>
     */
    private static function cleanJson(array $details): array
    {
        $clean = [];

        foreach ($details as $key => $value) {
            $key = \is_string($key) ? self::cleanText($key, self::DETAIL_KEY_LIMIT) : $key;

            $clean[$key] = match (true) {
                \is_array($value) => self::cleanJson($value),
                \is_string($value) => self::cleanText($value, self::DETAIL_VALUE_LIMIT),
                \is_float($value) => is_finite($value) ? $value : null,
                $value === null, \is_scalar($value) => $value,
                default => null,
            };
        }

        return $clean;
    }

    /**
     * Every row here is a security event now — page views are counted, not
     * logged (see purgeAll()'s docblock) — so there is no event_type filter
     * left to offer.
     *
     * @return list<array<string, mixed>>
     */
    public function findLiveFeed(int $limit = 40, ?int $afterId = null): array
    {
        try {
            $sql = 'SELECT t.id, t.ip_address, t.user_id, t.request_method, t.request_uri, t.user_agent,
                           t.severity, t.event_type, t.threat_score, t.details, t.created_at,
                           u.username, u.email
                    FROM cp_system_telemetry_logs t
                    LEFT JOIN cp_users u ON u.id = t.user_id';
            $params = [];
            if ($afterId !== null && $afterId > 0) {
                $sql .= ' WHERE t.id > :afterId';
                $params['afterId'] = $afterId;
            }
            $sql .= ' ORDER BY t.id DESC LIMIT '.$limit;

            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (DBALException) {
            return [];
        }

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Compact 24h security strip for the AACP dashboard (visitor mode companion).
     *
     * @return array{threats: int, warnings: int, critical: int, events: int}
     */
    public function securitySummary(int $hours = 24): array
    {
        $empty = ['threats' => 0, 'warnings' => 0, 'critical' => 0, 'events' => 0];

        try {
            $since = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
            $row = $this->connection->fetchAssociative(
                "SELECT
                    SUM(CASE WHEN severity = 'threat' THEN 1 ELSE 0 END) AS threats,
                    SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) AS warnings,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS criticals,
                    SUM(CASE WHEN event_type <> 'page_view' THEN 1 ELSE 0 END) AS events
                 FROM cp_system_telemetry_logs
                 WHERE created_at >= :since",
                ['since' => $since],
            );
        } catch (DBALException) {
            return $empty;
        }

        if ($row === false) {
            return $empty;
        }

        return [
            'threats' => (int) ($row['threats'] ?? 0),
            'warnings' => (int) ($row['warnings'] ?? 0),
            'critical' => (int) ($row['criticals'] ?? 0),
            'events' => (int) ($row['events'] ?? 0),
        ];
    }

    /**
     * Hourly buckets for the last 24 hours: normal page views vs threat-class hits.
     *
     * @return array{labels: list<string>, normal: list<int>, threats: list<int>}
     */
    public function hourlyTrend(int $hours = 24): array
    {
        $labels = [];
        $keys = [];
        $normal = [];
        $threats = [];
        $now = new \DateTimeImmutable();

        for ($i = $hours - 1; $i >= 0; --$i) {
            $bucket = $now->modify(sprintf('-%d hours', $i))->setTime((int) $now->modify(sprintf('-%d hours', $i))->format('H'), 0, 0);
            $keys[] = $bucket->format('Y-m-d H:00:00');
            $labels[] = $bucket->format('H:00');
            $normal[] = 0;
            $threats[] = 0;
        }

        try {
            $since = $now->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\') AS bucket,
                        SUM(CASE WHEN severity IN (\'info\') THEN 1 ELSE 0 END) AS normal_hits,
                        SUM(CASE WHEN severity IN (\'warning\', \'critical\', \'threat\') THEN 1 ELSE 0 END) AS threat_hits
                 FROM cp_system_telemetry_logs
                 WHERE created_at >= :since
                 GROUP BY bucket
                 ORDER BY bucket ASC',
                ['since' => $since],
            );
        } catch (DBALException) {
            return ['labels' => $labels, 'normal' => $normal, 'threats' => $threats];
        }

        $indexByKey = [];
        foreach ($rows as $row) {
            $indexByKey[(string) $row['bucket']] = $row;
        }

        foreach ($keys as $i => $key) {
            if (!isset($indexByKey[$key])) {
                continue;
            }
            $normal[$i] = (int) $indexByKey[$key]['normal_hits'];
            $threats[$i] = (int) $indexByKey[$key]['threat_hits'];
        }

        return ['labels' => $labels, 'normal' => $normal, 'threats' => $threats];
    }

    /**
     * @return list<array{eventType: string, count: int}>
     */
    public function vectorBreakdown(int $hours = 24): array
    {
        $types = [
            SystemTelemetryLog::EVENT_SQLI_ATTEMPT,
            SystemTelemetryLog::EVENT_XSS_ATTEMPT,
            SystemTelemetryLog::EVENT_SCANNER_DETECTED,
            SystemTelemetryLog::EVENT_PATH_TRAVERSAL,
            SystemTelemetryLog::EVENT_LOGIN_ATTEMPT,
        ];

        try {
            $since = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
            $quoted = implode(',', array_map(
                fn (string $type): string => $this->connection->quote($type),
                $types,
            ));
            $rows = $this->connection->fetchAllAssociative(
                'SELECT event_type, COUNT(*) AS cnt
                 FROM cp_system_telemetry_logs
                 WHERE created_at >= :since AND event_type IN ('.$quoted.')
                 GROUP BY event_type',
                ['since' => $since],
            );
        } catch (DBALException) {
            $rows = [];
        }

        $counts = array_fill_keys($types, 0);
        foreach ($rows as $row) {
            $type = (string) $row['event_type'];
            if (isset($counts[$type])) {
                $counts[$type] = (int) $row['cnt'];
            }
        }

        $out = [];
        foreach ($counts as $eventType => $count) {
            $out[] = ['eventType' => $eventType, 'count' => $count];
        }

        return $out;
    }

    /**
     * @return list<array{ip: string, score: int, hits: int, lastEvent: string, banned: bool}>
     */
    public function topThreatIps(int $limit = 5): array
    {
        try {
            $since = (new \DateTimeImmutable())->modify('-24 hours')->format('Y-m-d H:i:s');
            $rows = $this->connection->fetchAllAssociative(
                'SELECT t.ip_address AS ip,
                        MAX(t.threat_score) AS score,
                        COUNT(*) AS hits,
                        SUBSTRING_INDEX(GROUP_CONCAT(t.event_type ORDER BY t.id DESC), \',\', 1) AS last_event,
                        (b.ip_address IS NOT NULL) AS banned
                 FROM cp_system_telemetry_logs t
                 LEFT JOIN cp_banned_ips b ON b.ip_address = t.ip_address
                 WHERE t.created_at >= :since AND t.threat_score > 0
                 GROUP BY t.ip_address
                 ORDER BY score DESC, hits DESC
                 LIMIT '.$limit,
                ['since' => $since],
            );
        } catch (DBALException) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ip' => (string) $row['ip'],
                'score' => (int) $row['score'],
                'hits' => (int) $row['hits'],
                'lastEvent' => (string) $row['last_event'],
                'banned' => (bool) $row['banned'],
            ];
        }

        return $out;
    }

    public function purgeOlderThan(string $severity, \DateTimeImmutable $before): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM cp_system_telemetry_logs WHERE severity = :severity AND created_at < :before',
                [
                    'severity' => $severity,
                    'before' => $before->format('Y-m-d H:i:s'),
                ],
            );
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $details = $row['details'] ?? [];
        if (\is_string($details)) {
            $decoded = json_decode($details, true);
            $details = \is_array($decoded) ? $decoded : [];
        }

        $username = trim((string) ($row['username'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $userLabel = $username !== '' ? $username : ($email !== '' ? $email : '—');

        $created = (string) ($row['created_at'] ?? '');
        $createdAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $created) ?: new \DateTimeImmutable($created);

        return [
            'id' => (int) $row['id'],
            'time' => $createdAt->format('H:i:s'),
            'createdAt' => $createdAt->format(DATE_ATOM),
            'severity' => (string) $row['severity'],
            'ip' => (string) $row['ip_address'],
            'user' => $row['user_id'] === null ? '—' : $userLabel,
            'method' => (string) $row['request_method'],
            'uri' => (string) $row['request_uri'],
            'eventType' => (string) $row['event_type'],
            'threatScore' => (int) $row['threat_score'],
            'userAgent' => (string) ($row['user_agent'] ?? ''),
            'details' => $details,
        ];
    }

    /**
     * Deletes every telemetry row regardless of severity.
     *
     * This table holds security events only — page views are counted by
     * VisitorStatsRecorder into cp_visitor_daily_stats and friends, a
     * separate table this never touches. That split is deliberate: clearing
     * the security log used to also wipe visitor history, because both lived
     * in this one table.
     *
     * Swallows DBAL failures like the rest of this repository: telemetry is
     * observability, and a panel that 500s while trying to free disk space is
     * worse than one that reports nothing removed.
     */
    public function purgeAll(): int
    {
        try {
            return (int) $this->connection->executeStatement('DELETE FROM cp_system_telemetry_logs');
        } catch (DBALException) {
            return 0;
        }
    }

    public function countAll(): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_system_telemetry_logs');
        } catch (DBALException) {
            return 0;
        }
    }
}
