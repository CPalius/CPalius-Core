<?php

declare(strict_types=1);

namespace App\Core\Security\Password;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only place that writes User::$password.
 *
 * Hashing, the rotation timestamp and the reuse history have to move together:
 * a caller that hashes by hand silently opts out of rotation and reuse checks,
 * which is exactly the kind of drift this service exists to prevent.
 */
final class PasswordChanger
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $policy,
    ) {
    }

    public function change(User $user, string $plainPassword): string
    {
        $hash = $this->passwordHasher->hashPassword($user, $plainPassword);

        $user->setPassword($hash);
        $user->setDataValue('password_changed_at', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));

        // A user that has not been flushed yet has no id to hang history off, and a
        // brand-new account has no previous password worth protecting anyway.
        $this->policy->remember($user, $hash);

        return $hash;
    }

    /**
     * Identity strings the policy uses to reject passwords built from the account's
     * own e-mail or name.
     *
     * @return list<string>
     */
    public function identityOf(User $user): array
    {
        return array_values(array_filter([
            $user->getEmail(),
            (string) $user->getUsername(),
            $user->getFirstName(),
            $user->getLastName(),
        ], static fn (string $value): bool => $value !== ''));
    }
}
