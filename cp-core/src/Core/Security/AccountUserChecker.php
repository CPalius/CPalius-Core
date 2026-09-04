<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Account\AccountRegistrationService;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Block pending accounts (approval / email verification) at login.
 */
final class AccountUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly AccountRegistrationService $registrationService,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getStatus() === User::STATUS_BANNED) {
            throw new CustomUserMessageAccountStatusException('account.login.banned');
        }

        if ($user->getStatus() === User::STATUS_INACTIVE) {
            throw new CustomUserMessageAccountStatusException('account.login.pending_approval');
        }

        if ($this->registrationService->isEmailVerificationRequired() && !$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException('account.login.email_not_verified');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
