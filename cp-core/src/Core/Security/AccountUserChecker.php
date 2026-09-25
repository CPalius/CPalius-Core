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
 *
 * These checks run in checkPostAuth(), not checkPreAuth(): Symfony's security
 * listeners run checkPreAuth() BEFORE the password is verified
 * (UserCheckerListener has a higher priority than CheckCredentialsListener on
 * CheckPassportEvent), so throwing a status-specific, translated message there
 * lets anyone submit a known email/username with any password and learn its
 * moderation state ("banned" / "pending approval" / "email not verified")
 * without a valid credential — a user-enumeration oracle, plus a timing
 * side-channel since these accounts skip password-hash verification entirely.
 * checkPostAuth() only runs once the password has already been confirmed
 * correct, so the same messages no longer leak anything to a guesser.
 */
final class AccountUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly AccountRegistrationService $registrationService,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
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

        if ($this->registrationService->isEmailVerificationRequired()
            && $this->registrationService->isLoginVerificationRequired()
            && !$user->isEmailVerified()
        ) {
            throw new CustomUserMessageAccountStatusException('account.login.email_not_verified');
        }
    }
}
