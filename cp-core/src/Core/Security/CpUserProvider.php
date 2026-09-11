<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Optional user provider: login by email OR username. Does not replace the email-only entity provider.
 * Unknown/inactive users throw UserNotFoundException so the firewall does not leak account existence.
 */
final class CpUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneByEmailOrUsername($identifier);

        if ($user === null) {
            throw new UserNotFoundException(sprintf('No user found with identifier "%s".', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', $user::class));
        }

        $freshUser = $this->userRepository->find($user->getId());

        if ($freshUser === null) {
            throw new UserNotFoundException(sprintf('User with ID #%d no longer exists.', $user->getId()));
        }

        return $freshUser;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->userRepository->upgradePassword($user, $newHashedPassword);
    }
}
