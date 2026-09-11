<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * IP blocklist for the request guard, the WAF and the login defence.
 *
 * Entries may be a literal address or a CIDR range, and may expire. The
 * allowlist is checked first and always wins, so an automatic ban can never cut
 * an operator off from their own office range.
 */
final class IpBanService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTO = 'auto';

    /** Range rows are scanned in PHP, so the working set is capped. */
    private const MAX_RANGES = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly IpMatcher $matcher,
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function isBanned(string $ip): bool
    {
        return $this->findMatch($ip) !== null;
    }

    /**
     * Returns the matching ban id, bumping its hit counter. Separate from
     * isBanned() so the guard can record a hit without a second lookup.
     */
    public function findMatch(string $ip): ?int
    {
        $ip = trim($ip);
        if ($ip === '' || $this->isAllowlisted($ip)) {
            return null;
        }

        // Bans are stored canonically (see ban()), but the address the request
        // arrives with may be spelled differently — "::ffff:1.2.3.4" for an IPv4
        // client on a dual-stack listener, or an expanded IPv6 form from a proxy.
        // Both spellings are offered to the prefilter so neither one walks past a
        // literal ban row.
        $canonical = $this->matcher->canonicalize($ip);

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, ip_address, is_range FROM cp_banned_ips
                 WHERE (ip_address = :ip OR ip_address = :canonical OR is_range = 1)
                   AND (expires_at IS NULL OR expires_at > :now)
                 LIMIT '.self::MAX_RANGES,
                ['ip' => $ip, 'canonical' => $canonical, 'now' => $this->now()],
            );
        } catch (DBALException) {
            return null;
        }

        foreach ($rows as $row) {
            $pattern = (string) $row['ip_address'];

            // matches() handles both literals and ranges and compares packed bytes,
            // so a textual difference in spelling no longer defeats the ban.
            if ($this->matcher->matches($ip, $pattern)) {
                $id = (int) $row['id'];
                $this->recordHit($id);

                return $id;
            }
        }

        return null;
    }

    public function isAllowlisted(string $ip): bool
    {
        return $this->matcher->matchesAny($ip, $this->allowlist());
    }

    /**
     * @return list<string>
     */
    public function allowlist(): array
    {
        return $this->matcher->parseList((string) ($this->settings->get('security.ip_allowlist') ?? ''));
    }

    /**
     * @param int|null $minutes null or 0 means a permanent ban
     */
    public function ban(
        string $pattern,
        ?int $bannedBy = null,
        ?int $minutes = null,
        ?string $reason = null,
        string $source = self::SOURCE_MANUAL,
    ): bool {
        $pattern = trim($pattern);
        if (!$this->matcher->isValidPattern($pattern)) {
            return false;
        }

        // Stored canonically so the row and an incoming request agree on one
        // spelling; findMatch() looks the canonical form up directly.
        $pattern = $this->matcher->canonicalizePattern($pattern);

        // Banning an allowlisted address is always an operator mistake or a WAF
        // false positive; refusing it keeps the allowlist authoritative. The check
        // used to skip ranges entirely, so "10.0.0.0/8" was accepted even when the
        // operator's own office address sat inside it — findMatch() would still let
        // them in, but the ban table then carried a row that reads as a lockout and
        // that a later change to the allowlist would silently activate.
        if ($this->coversAllowlistedAddress($pattern)) {
            return false;
        }

        $expiresAt = $minutes !== null && $minutes > 0
            ? (new \DateTimeImmutable('+'.$minutes.' minutes'))->format('Y-m-d H:i:s')
            : null;

        try {
            $existingId = $this->connection->fetchOne(
                'SELECT id FROM cp_banned_ips WHERE ip_address = :ip LIMIT 1',
                ['ip' => $pattern],
            );

            if ($existingId !== false && $existingId !== null) {
                // Re-banning extends the window; an automatic ban never shortens a
                // manual one. Written as a portable CASE rather than GREATEST():
                // GREATEST is a MySQL/PostgreSQL function that SQLite does not have,
                // so on SQLite the whole statement raised and ban() reported failure
                // for what is in fact the most common path — re-banning a host that
                // is already on the list.
                $this->connection->executeStatement(
                    'UPDATE cp_banned_ips
                        SET expires_at = CASE
                                WHEN :expires IS NULL OR expires_at IS NULL THEN NULL
                                WHEN expires_at > :expires THEN expires_at
                                ELSE :expires END,
                            reason = COALESCE(:reason, reason)
                      WHERE id = :id',
                    ['expires' => $expiresAt, 'reason' => $reason, 'id' => (int) $existingId],
                );

                return true;
            }

            $this->connection->insert('cp_banned_ips', [
                'ip_address' => $pattern,
                'banned_by' => $bannedBy,
                'created_at' => $this->now(),
                'expires_at' => $expiresAt,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'source' => $source === self::SOURCE_AUTO ? self::SOURCE_AUTO : self::SOURCE_MANUAL,
                'is_range' => str_contains($pattern, '/') ? 1 : 0,
                'hit_count' => 0,
            ]);
        } catch (DBALException) {
            return false;
        }

        return true;
    }

    public function unban(string $pattern): bool
    {
        $pattern = trim($pattern);
        $canonical = $this->matcher->canonicalizePattern($pattern);

        try {
            $removed = $this->connection->delete('cp_banned_ips', ['ip_address' => $pattern]);

            // Rows written before canonical storage (or typed in another spelling)
            // would otherwise be un-unbannable from the AACP ban table.
            if ($canonical !== $pattern) {
                $removed += $this->connection->delete('cp_banned_ips', ['ip_address' => $canonical]);
            }

            return $removed > 0;
        } catch (DBALException) {
            return false;
        }
    }

    /**
     * True when $pattern would block an address the operator has allowlisted.
     * A literal is checked against the allowlist directly; a range is checked the
     * other way round, asking whether any allowlisted entry falls inside it.
     */
    private function coversAllowlistedAddress(string $pattern): bool
    {
        if (!str_contains($pattern, '/')) {
            return $this->isAllowlisted($pattern);
        }

        foreach ($this->allowlist() as $allowed) {
            // Only literal allowlist entries can be tested for containment; an
            // allowlisted range against a banned range needs subnet arithmetic the
            // matcher deliberately does not do, and the allowlist wins at check
            // time anyway.
            if (!str_contains($allowed, '/') && $this->matcher->matches($allowed, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function unbanById(int $id): bool
    {
        try {
            return $this->connection->delete('cp_banned_ips', ['id' => $id]) > 0;
        } catch (DBALException) {
            return false;
        }
    }

    public function purgeExpired(): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM cp_banned_ips WHERE expires_at IS NOT NULL AND expires_at <= :now',
                ['now' => $this->now()],
            );
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBans(int $limit = 100): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT b.id, b.ip_address, b.is_range, b.source, b.reason, b.hit_count,
                        b.created_at, b.expires_at, b.last_hit_at, u.email AS banned_by_email
                   FROM cp_banned_ips b
                   LEFT JOIN users u ON u.id = b.banned_by
                  ORDER BY b.created_at DESC
                  LIMIT '.max(1, min(500, $limit)),
            );

            return $rows;
        } catch (DBALException) {
            return [];
        }
    }

    /**
     * @return array{total: int, automatic: int, expiring: int}
     */
    public function stats(): array
    {
        try {
            /** @var array<string, mixed>|false $row */
            $row = $this->connection->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN source = 'auto' THEN 1 ELSE 0 END) AS automatic,
                        SUM(CASE WHEN expires_at IS NOT NULL THEN 1 ELSE 0 END) AS expiring
                   FROM cp_banned_ips
                  WHERE expires_at IS NULL OR expires_at > :now",
                ['now' => $this->now()],
            );
        } catch (DBALException) {
            return ['total' => 0, 'automatic' => 0, 'expiring' => 0];
        }

        if ($row === false) {
            return ['total' => 0, 'automatic' => 0, 'expiring' => 0];
        }

        return [
            'total' => (int) $row['total'],
            'automatic' => (int) $row['automatic'],
            'expiring' => (int) $row['expiring'],
        ];
    }

    private function recordHit(int $id): void
    {
        try {
            $this->connection->executeStatement(
                'UPDATE cp_banned_ips SET hit_count = hit_count + 1, last_hit_at = :now WHERE id = :id',
                ['now' => $this->now(), 'id' => $id],
            );
        } catch (DBALException) {
            // Counters are diagnostics; the block already happened.
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
