<?php

declare(strict_types=1);

namespace App\Core\Security\Session;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tracks sessions per account for remote revoke. Session ids are stored hashed.
 */
final class SessionRegistry
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function touch(User $user, string $sessionId, Request $request): void
    {
        if ($user->getId() === null || $sessionId === '') {
            return;
        }

        $now = $this->now();
        $hash = $this->hash($sessionId);

        try {
            $updated = $this->connection->executeStatement(
                'UPDATE cp_user_sessions SET last_seen_at = :now, ip_address = :ip WHERE session_hash = :hash',
                ['now' => $now, 'ip' => $this->ip($request), 'hash' => $hash],
            );

            if ($updated > 0) {
                return;
            }

            $this->connection->insert('cp_user_sessions', [
                'user_id' => $user->getId(),
                'session_hash' => $hash,
                'ip_address' => $this->ip($request),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 255),
                'fingerprint' => $this->fingerprint($request),
                'created_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (DBALException) {
            // The registry is observability plus revocation; losing a write only
            // means one session is not listed.
        }
    }

    public function isRevoked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        try {
            $value = $this->connection->fetchOne(
                'SELECT 1 FROM cp_user_sessions WHERE session_hash = :hash AND revoked_at IS NOT NULL LIMIT 1',
                ['hash' => $this->hash($sessionId)],
            );
        } catch (DBALException) {
            return false;
        }

        return $value !== false && $value !== null;
    }

    public function revokeById(int $id): bool
    {
        try {
            return $this->connection->executeStatement(
                'UPDATE cp_user_sessions SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
                ['now' => $this->now(), 'id' => $id],
            ) > 0;
        } catch (DBALException) {
            return false;
        }
    }

    /**
     * Revoke scoped to one account, for the member-facing session list. The
     * unscoped revokeById() is an operator tool; handing it a row id from a
     * request body would let anybody sign anybody else out.
     */
    public function revokeByIdForUser(int $id, User $user): bool
    {
        if ($user->getId() === null) {
            return false;
        }

        try {
            return $this->connection->executeStatement(
                'UPDATE cp_user_sessions SET revoked_at = :now WHERE id = :id AND user_id = :user AND revoked_at IS NULL',
                ['now' => $this->now(), 'id' => $id, 'user' => $user->getId()],
            ) > 0;
        } catch (DBALException) {
            return false;
        }
    }

    /** Row id of the session making this request, so the list can mark "this device". */
    public function idForSession(string $sessionId): ?int
    {
        if ($sessionId === '') {
            return null;
        }

        try {
            $value = $this->connection->fetchOne(
                'SELECT id FROM cp_user_sessions WHERE session_hash = :hash LIMIT 1',
                ['hash' => $this->hash($sessionId)],
            );
        } catch (DBALException) {
            return null;
        }

        return $value === false || $value === null ? null : (int) $value;
    }

    /** @param string|null $exceptSessionId current session to keep when signing out everywhere else */
    public function revokeAllForUser(User $user, ?string $exceptSessionId = null): int
    {
        if ($user->getId() === null) {
            return 0;
        }

        $sql = 'UPDATE cp_user_sessions SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL';
        $params = ['now' => $this->now(), 'user' => $user->getId()];

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $sql .= ' AND session_hash <> :keep';
            $params['keep'] = $this->hash($exceptSessionId);
        }

        try {
            return (int) $this->connection->executeStatement($sql, $params);
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * Marks every still-live session last seen before $before as revoked.
     *
     * The per-request guard already signs an idle visitor out when they come
     * back — but "when they come back" is exactly the window an attacker with a
     * stolen cookie is working in. Revoking on a schedule closes the session
     * server-side whether or not anybody returns to it.
     *
     * @param list<int>|null $userIds restrict to these accounts; null means all
     *
     * @return int rows revoked
     */
    public function revokeIdleBefore(\DateTimeImmutable $before, ?array $userIds = null): int
    {
        if ($userIds !== null && $userIds === []) {
            return 0;
        }

        $sql = 'UPDATE cp_user_sessions SET revoked_at = :now WHERE revoked_at IS NULL AND last_seen_at < :before';
        $params = ['now' => $this->now(), 'before' => $before->format('Y-m-d H:i:s')];
        $types = [];

        if ($userIds !== null) {
            $sql .= ' AND user_id IN (:users)';
            $params['users'] = $userIds;
            $types['users'] = ArrayParameterType::INTEGER;
        }

        try {
            return (int) $this->connection->executeStatement($sql, $params, $types);
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * Accounts holding a live session last seen before $before. The sweep uses
     * this to decide which of them are privileged without loading every row.
     *
     * @return list<int>
     */
    public function idleUserIds(\DateTimeImmutable $before): array
    {
        try {
            /** @var list<mixed> $ids */
            $ids = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT user_id FROM cp_user_sessions
                  WHERE revoked_at IS NULL AND user_id IS NOT NULL AND last_seen_at < :before',
                ['before' => $before->format('Y-m-d H:i:s')],
            );

            return array_map(static fn (mixed $id): int => (int) $id, $ids);
        } catch (DBALException) {
            return [];
        }
    }

    public function forget(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            $this->connection->delete('cp_user_sessions', ['session_hash' => $this->hash($sessionId)]);
        } catch (DBALException) {
            // Stale rows are pruned by the maintenance task.
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user, int $limit = 50): array
    {
        if ($user->getId() === null) {
            return [];
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, ip_address, user_agent, created_at, last_seen_at, revoked_at
                   FROM cp_user_sessions
                  WHERE user_id = :user
                  ORDER BY last_seen_at DESC
                  LIMIT '.max(1, min(200, $limit)),
                ['user' => $user->getId()],
            );

            return $rows;
        } catch (DBALException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(int $limit = 100): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT s.id, s.user_id, s.ip_address, s.user_agent, s.created_at, s.last_seen_at,
                        u.email, u.username
                   FROM cp_user_sessions s
                   LEFT JOIN cp_users u ON u.id = s.user_id
                  WHERE s.revoked_at IS NULL
                  ORDER BY s.last_seen_at DESC
                  LIMIT '.max(1, min(500, $limit)),
            );

            return $rows;
        } catch (DBALException) {
            return [];
        }
    }

    public function countActive(): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM cp_user_sessions WHERE revoked_at IS NULL',
            );
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * Drops rows for sessions that have not been seen for a while, revoked or not.
     */
    public function purgeStale(int $days = 30): int
    {
        $days = max(1, $days);

        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM cp_user_sessions WHERE last_seen_at < :before',
                ['before' => (new \DateTimeImmutable('-'.$days.' days'))->format('Y-m-d H:i:s')],
            );
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * Request fingerprint for session binding. IP is excluded — mobile networks change it legitimately.
     */
    public function fingerprint(Request $request): string
    {
        return hash('sha256', (string) $request->headers->get('User-Agent', ''));
    }

    private function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function ip(Request $request): string
    {
        return mb_substr((string) ($request->getClientIp() ?: '0.0.0.0'), 0, 45);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
