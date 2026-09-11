<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Password\PasswordHistory;
use App\Entity\User;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[CoversClass(PasswordHistory::class)]
final class PasswordHistoryTest extends TestCase
{
    private Connection $connection;
    private PasswordHasherFactoryInterface $hasherFactory;
    private PasswordHistory $history;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();

        // Real hashers, at the cheapest cost the algorithm allows: the history
        // check is a real bcrypt verify and mocking it would test nothing.
        $this->hasherFactory = new PasswordHasherFactory([
            PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
        ]);

        $this->history = new PasswordHistory($this->connection, $this->hasherFactory);
        $this->user = SecurityTestDatabase::userWithId(42);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function hash(string $plain): string
    {
        return (new UserPasswordHasher($this->hasherFactory))->hashPassword($this->user, $plain);
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_password_history WHERE user_id = 42');
    }

    public function testAnUnusedPasswordIsNotReported(): void
    {
        $this->history->remember($this->user, $this->hash('first-password'), 5);

        self::assertFalse($this->history->isReused($this->user, 'a-brand-new-password', 5));
    }

    public function testAPreviouslyUsedPasswordIsRejected(): void
    {
        $this->history->remember($this->user, $this->hash('first-password'), 5);

        self::assertTrue($this->history->isReused($this->user, 'first-password', 5));
    }

    public function testAnyPasswordInsideTheDepthIsRejected(): void
    {
        foreach (['one', 'two', 'three'] as $plain) {
            $this->history->remember($this->user, $this->hash($plain.'-password'), 5);
        }

        self::assertTrue($this->history->isReused($this->user, 'one-password', 5));
        self::assertTrue($this->history->isReused($this->user, 'two-password', 5));
        self::assertTrue($this->history->isReused($this->user, 'three-password', 5));
    }

    public function testHistoryIsTrimmedToTheConfiguredDepth(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            $this->history->remember($this->user, $this->hash('password-'.$i), 3);
        }

        self::assertSame(3, $this->rows(), 'Only the configured depth is kept.');
        self::assertTrue($this->history->isReused($this->user, 'password-8', 3));
        self::assertFalse($this->history->isReused($this->user, 'password-1', 3), 'Trimmed entries are forgotten.');
    }

    public function testDepthIsCappedSoAnAbsurdSettingCannotKeepEverything(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->history->remember($this->user, $this->hash('password-'.$i), 9999);
        }

        // MAX_DEPTH is 10.
        self::assertSame(10, $this->rows());
    }

    public function testAZeroOrNegativeDepthNeverReportsReuse(): void
    {
        $this->history->remember($this->user, $this->hash('first-password'), 5);

        self::assertFalse($this->history->isReused($this->user, 'first-password', 0));
        self::assertFalse($this->history->isReused($this->user, 'first-password', -1));
    }

    public function testAnEmptyPasswordIsNeverReportedAsReused(): void
    {
        $this->history->remember($this->user, $this->hash('first-password'), 5);

        self::assertFalse($this->history->isReused($this->user, '', 5));
    }

    public function testHistoryIsScopedToOneAccount(): void
    {
        $other = SecurityTestDatabase::userWithId(43, 'veli@example.com');

        $this->history->remember($this->user, $this->hash('shared-password'), 5);

        self::assertTrue($this->history->isReused($this->user, 'shared-password', 5));
        self::assertFalse($this->history->isReused($other, 'shared-password', 5));
    }

    public function testAnUnpersistedUserIsIgnoredEntirely(): void
    {
        $fresh = new User('new@example.com');

        $this->history->remember($fresh, $this->hash('whatever'), 5);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_password_history'));
        self::assertFalse($this->history->isReused($fresh, 'whatever', 5));
    }

    public function testAnEmptyHashIsNotRecorded(): void
    {
        $this->history->remember($this->user, '', 5);

        self::assertSame(0, $this->rows());
    }

    public function testForgetDropsEveryRowForTheAccountOnly(): void
    {
        $other = SecurityTestDatabase::userWithId(43, 'veli@example.com');
        $this->history->remember($this->user, $this->hash('mine'), 5);
        $this->history->remember($other, $this->hash('theirs'), 5);

        $this->history->forget($this->user);

        self::assertSame(0, $this->rows());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_password_history WHERE user_id = 43'));
    }

    /**
     * A hash from a retired algorithm must be skipped, not raise: a user whose
     * history predates an algorithm change still has to be able to change their
     * password.
     */
    public function testAnUnverifiableStoredHashIsSkippedRatherThanFatal(): void
    {
        $this->connection->insert('cp_password_history', [
            'user_id' => 42,
            'password_hash' => 'not-a-hash-at-all',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->history->remember($this->user, $this->hash('real-password'), 5);

        self::assertTrue($this->history->isReused($this->user, 'real-password', 5));
        self::assertFalse($this->history->isReused($this->user, 'something-else', 5));
    }

    public function testAMissingTableDegradesQuietly(): void
    {
        $this->connection->executeStatement('DROP TABLE cp_password_history');

        $this->history->remember($this->user, $this->hash('x'), 5);
        $this->history->forget($this->user);

        self::assertFalse($this->history->isReused($this->user, 'x', 5));
    }
}
