<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Session\SessionRegistry;
use App\Entity\User;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(SessionRegistry::class)]
final class SessionRegistryTest extends TestCase
{
    private const SESSION_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    private Connection $connection;
    private SessionRegistry $registry;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
        $this->registry = new SessionRegistry($this->connection);
        $this->user = SecurityTestDatabase::userWithId(42);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function request(string $ip = '203.0.113.9', string $userAgent = 'Mozilla/5.0 Firefox'): Request
    {
        $request = Request::create('/tr');
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', $userAgent);

        return $request;
    }

    /**
     * The table is a security feature. A readable session id in it would be a
     * hijacking shortcut for anyone who could read one row — a read-only SQL
     * injection, a shared backup, a support export.
     */
    public function testTheSessionIdIsNeverStoredInPlaintext(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());

        $stored = $this->connection->fetchAllAssociative('SELECT * FROM cp_user_sessions');
        self::assertCount(1, $stored);

        foreach ($stored[0] as $value) {
            self::assertStringNotContainsString(self::SESSION_ID, (string) $value);
        }

        self::assertSame(hash('sha256', self::SESSION_ID), $stored[0]['session_hash']);
        self::assertSame(64, \strlen((string) $stored[0]['session_hash']));
    }

    public function testTouchInsertsOnceAndUpdatesAfterwards(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request('203.0.113.9'));
        $this->registry->touch($this->user, self::SESSION_ID, $this->request('198.51.100.4'));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_user_sessions'));
        self::assertSame(
            '198.51.100.4',
            $this->connection->fetchOne('SELECT ip_address FROM cp_user_sessions'),
        );
    }

    public function testDifferentSessionsForTheSameUserAreSeparateRows(): void
    {
        $this->registry->touch($this->user, 'session-one', $this->request());
        $this->registry->touch($this->user, 'session-two', $this->request());

        self::assertCount(2, $this->registry->listForUser($this->user));
        self::assertSame(2, $this->registry->countActive());
    }

    public function testAnUnpersistedUserOrAnEmptySessionIdIsIgnored(): void
    {
        $this->registry->touch(new User('fresh@example.com'), self::SESSION_ID, $this->request());
        $this->registry->touch($this->user, '', $this->request());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_user_sessions'));
    }

    public function testUserAgentIsTruncatedToTheColumnWidth(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request(userAgent: str_repeat('U', 400)));

        self::assertSame(255, mb_strlen((string) $this->connection->fetchOne('SELECT user_agent FROM cp_user_sessions')));
    }

    // --- revocation --------------------------------------------------------

    public function testAFreshSessionIsNotRevoked(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());

        self::assertFalse($this->registry->isRevoked(self::SESSION_ID));
    }

    public function testAnEmptySessionIdIsNeverRevoked(): void
    {
        self::assertFalse($this->registry->isRevoked(''));
    }

    public function testAnUnknownSessionIsNotRevoked(): void
    {
        self::assertFalse($this->registry->isRevoked('never-seen-before'));
    }

    public function testRevokeByIdMarksExactlyOneSession(): void
    {
        $this->registry->touch($this->user, 'session-one', $this->request());
        $this->registry->touch($this->user, 'session-two', $this->request());

        $rows = $this->registry->listForUser($this->user);
        $id = (int) $rows[0]['id'];

        self::assertTrue($this->registry->revokeById($id));
        self::assertFalse($this->registry->revokeById($id), 'Revoking twice is a no-op.');
        self::assertSame(1, $this->registry->countActive());
    }

    public function testARevokedSessionIsReportedAsRevoked(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());
        $id = (int) $this->registry->listForUser($this->user)[0]['id'];

        $this->registry->revokeById($id);

        self::assertTrue($this->registry->isRevoked(self::SESSION_ID));
    }

    public function testRevokeAllForUserSpareTheCallersOwnSession(): void
    {
        $this->registry->touch($this->user, 'mine', $this->request());
        $this->registry->touch($this->user, 'other-one', $this->request());
        $this->registry->touch($this->user, 'other-two', $this->request());

        self::assertSame(2, $this->registry->revokeAllForUser($this->user, 'mine'));

        self::assertFalse($this->registry->isRevoked('mine'));
        self::assertTrue($this->registry->isRevoked('other-one'));
        self::assertTrue($this->registry->isRevoked('other-two'));
    }

    public function testRevokeAllForUserWithoutAnExceptionRevokesEverything(): void
    {
        $this->registry->touch($this->user, 'one', $this->request());
        $this->registry->touch($this->user, 'two', $this->request());

        self::assertSame(2, $this->registry->revokeAllForUser($this->user));
        self::assertSame(0, $this->registry->countActive());
    }

    public function testRevokeAllForUserTouchesNobodyElse(): void
    {
        $other = SecurityTestDatabase::userWithId(43, 'veli@example.com');
        $this->registry->touch($this->user, 'mine', $this->request());
        $this->registry->touch($other, 'theirs', $this->request());

        $this->registry->revokeAllForUser($this->user);

        self::assertTrue($this->registry->isRevoked('mine'));
        self::assertFalse($this->registry->isRevoked('theirs'));
    }

    public function testRevokeAllForAnUnpersistedUserDoesNothing(): void
    {
        self::assertSame(0, $this->registry->revokeAllForUser(new User('fresh@example.com')));
    }

    public function testForgetRemovesTheRowEntirely(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());

        $this->registry->forget(self::SESSION_ID);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_user_sessions'));
        self::assertFalse($this->registry->isRevoked(self::SESSION_ID));
    }

    // --- lifetime ----------------------------------------------------------

    public function testPurgeStaleDropsOnlySessionsOlderThanTheCutoff(): void
    {
        $this->registry->touch($this->user, 'recent', $this->request());
        $this->registry->touch($this->user, 'ancient', $this->request());

        $this->connection->executeStatement(
            'UPDATE cp_user_sessions SET last_seen_at = :old WHERE session_hash = :hash',
            [
                'old' => (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s'),
                'hash' => hash('sha256', 'ancient'),
            ],
        );

        self::assertSame(1, $this->registry->purgeStale(30));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_user_sessions'));
    }

    public function testPurgeStaleRemovesRevokedRowsToo(): void
    {
        $this->registry->touch($this->user, 'ancient', $this->request());
        $this->registry->revokeAllForUser($this->user);
        $this->connection->executeStatement(
            'UPDATE cp_user_sessions SET last_seen_at = :old',
            ['old' => (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s')],
        );

        self::assertSame(1, $this->registry->purgeStale(30));
    }

    public function testPurgeStaleNeverAcceptsALessThanOneDayWindow(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());

        // A zero-day cutoff would otherwise delete every live session.
        self::assertSame(0, $this->registry->purgeStale(0));
        self::assertSame(0, $this->registry->purgeStale(-10));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_user_sessions'));
    }

    // --- binding -----------------------------------------------------------

    public function testTheFingerprintIsAHashOfTheUserAgentAndNothingElse(): void
    {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64)';

        self::assertSame(hash('sha256', $agent), $this->registry->fingerprint($this->request(userAgent: $agent)));
    }

    public function testTheFingerprintIgnoresTheClientIp(): void
    {
        // Mobile networks change the IP legitimately; binding on it here would log
        // people out for changing cell towers.
        self::assertSame(
            $this->registry->fingerprint($this->request('203.0.113.9')),
            $this->registry->fingerprint($this->request('198.51.100.4')),
        );
    }

    public function testTheFingerprintChangesWithTheUserAgent(): void
    {
        self::assertNotSame(
            $this->registry->fingerprint($this->request(userAgent: 'Firefox')),
            $this->registry->fingerprint($this->request(userAgent: 'Chrome')),
        );
    }

    // --- listing -----------------------------------------------------------

    public function testListActiveExcludesRevokedSessions(): void
    {
        $this->registry->touch($this->user, 'live', $this->request());
        $this->registry->touch($this->user, 'dead', $this->request());
        $this->registry->revokeAllForUser($this->user, 'live');

        $active = $this->registry->listActive();

        self::assertCount(1, $active);
        self::assertArrayHasKey('email', $active[0]);
    }

    public function testListForAnUnpersistedUserIsEmpty(): void
    {
        self::assertSame([], $this->registry->listForUser(new User('fresh@example.com')));
    }

    public function testEveryOperationDegradesQuietlyWithoutTheTable(): void
    {
        $this->registry->touch($this->user, self::SESSION_ID, $this->request());
        $this->connection->executeStatement('DROP TABLE cp_user_sessions');

        $this->registry->touch($this->user, self::SESSION_ID, $this->request());
        $this->registry->forget(self::SESSION_ID);

        self::assertFalse($this->registry->isRevoked(self::SESSION_ID));
        self::assertFalse($this->registry->revokeById(1));
        self::assertSame(0, $this->registry->revokeAllForUser($this->user));
        self::assertSame([], $this->registry->listForUser($this->user));
        self::assertSame([], $this->registry->listActive());
        self::assertSame(0, $this->registry->countActive());
        self::assertSame(0, $this->registry->purgeStale());
    }
}
