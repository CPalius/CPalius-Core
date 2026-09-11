<?php

declare(strict_types=1);

namespace App\Core\Security\Password;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Remembers previous password hashes so a rotation cannot cycle back to the
 * password that was just retired.
 *
 * Only ever consulted on a password change: verifying against N bcrypt/argon
 * hashes is intentionally expensive and has no place on the login path.
 */
final class PasswordHistory
{
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function isReused(User $user, string $plainPassword, int $depth): bool
    {
        $depth = min(self::MAX_DEPTH, $depth);
        if ($depth <= 0 || $plainPassword === '' || $user->getId() === null) {
            return false;
        }

        $hasher = $this->hasherFactory->getPasswordHasher($user);

        foreach ($this->recentHashes($user, $depth) as $hash) {
            try {
                if ($hasher->verify($hash, $plainPassword)) {
                    return true;
                }
            } catch (\Throwable) {
                // A hash from a retired algorithm simply cannot be matched.
            }
        }

        return false;
    }

    public function remember(User $user, string $passwordHash, int $depth): void
    {
        if ($user->getId() === null || $passwordHash === '') {
            return;
        }

        try {
            $this->connection->insert('cp_password_history', [
                'user_id' => $user->getId(),
                'password_hash' => $passwordHash,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (DBALException) {
            return;
        }

        $this->prune($user, min(self::MAX_DEPTH, max(1, $depth)));
    }

    public function forget(User $user): void
    {
        if ($user->getId() === null) {
            return;
        }

        try {
            $this->connection->delete('cp_password_history', ['user_id' => $user->getId()]);
        } catch (DBALException) {
            // Nothing to clean up.
        }
    }

    /**
     * @return list<string>
     */
    private function recentHashes(User $user, int $depth): array
    {
        try {
            /** @var list<string> $hashes */
            $hashes = $this->connection->fetchFirstColumn(
                'SELECT password_hash FROM cp_password_history
                  WHERE user_id = :user
                  ORDER BY created_at DESC, id DESC
                  LIMIT '.$depth,
                ['user' => $user->getId()],
            );

            return $hashes;
        } catch (DBALException) {
            return [];
        }
    }

    private function prune(User $user, int $depth): void
    {
        try {
            /** @var list<int|string> $keep */
            $keep = $this->connection->fetchFirstColumn(
                'SELECT id FROM cp_password_history
                  WHERE user_id = :user
                  ORDER BY created_at DESC, id DESC
                  LIMIT '.$depth,
                ['user' => $user->getId()],
            );

            if ($keep === []) {
                return;
            }

            $this->connection->executeStatement(
                'DELETE FROM cp_password_history WHERE user_id = :user AND id NOT IN (:keep)',
                ['user' => $user->getId(), 'keep' => array_map('intval', $keep)],
                ['keep' => ArrayParameterType::INTEGER],
            );
        } catch (DBALException) {
            // A few extra rows are harmless; the depth limit is advisory.
        }
    }
}
