<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Mail\Template\CoreMailTemplates;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * E-mail and username changes, held for an administrator.
 *
 * Until now the profile form wrote both straight to the account. That is a
 * quiet account-takeover primitive: whoever holds a session can point the
 * address that receives password resets at themselves, and can take a username
 * another member is known by — both without anyone being told. The fix is not
 * to forbid the change but to make somebody approve it.
 *
 * The request lives in `User::$data` rather than its own table. One member can
 * have at most one outstanding change (asking again replaces the first), which
 * is the product rule as well as the storage shape, and it keeps the feature
 * inside the existing users screen instead of adding a table an operator has to
 * learn about.
 *
 * Nothing about the account changes while a request is outstanding: the member
 * keeps logging in with the old address, and a rejected request leaves no
 * trace. That is what makes the queue safe to leave unattended.
 */
final class AccountIdentityChangeService
{
    public const FIELD_EMAIL = 'email';
    public const FIELD_USERNAME = 'username';

    /** Matches the registration rule; a changed username must satisfy the same one. */
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_]{3,50}$/';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly AccountMailer $accountMailer,
    ) {
    }

    public function isApprovalRequired(): bool
    {
        return (bool) ($this->settings->get('account.require_identity_change_approval') ?? true);
    }

    /**
     * @return array{email: ?string, username: ?string, requestedAt: ?string}|null
     */
    public function pendingFor(User $user): ?array
    {
        $raw = $user->getDataValue(User::DATA_IDENTITY_CHANGE);

        if (!\is_array($raw)) {
            return null;
        }

        $email = \is_string($raw[self::FIELD_EMAIL] ?? null) ? $raw[self::FIELD_EMAIL] : null;
        $username = \is_string($raw[self::FIELD_USERNAME] ?? null) ? $raw[self::FIELD_USERNAME] : null;

        if ($email === null && $username === null) {
            return null;
        }

        return [
            'email' => $email,
            'username' => $username,
            'requestedAt' => \is_string($raw['requested_at'] ?? null) ? $raw['requested_at'] : null,
        ];
    }

    /**
     * @return list<User>
     */
    public function pendingRequests(): array
    {
        return $this->users->findWithPendingIdentityChange();
    }

    public function pendingCount(): int
    {
        return \count($this->pendingRequests());
    }

    /**
     * Records what the member asked for, or applies it immediately when the
     * operator has turned approval off.
     *
     * Returns the errors to show on the form; an empty list means the request
     * was accepted. Whether it was applied or queued is answered by
     * pendingFor() afterwards, so the caller never has to interpret a status
     * enum it would then have to keep in step with this class.
     *
     * @return list<string>
     */
    public function submit(User $user, string $requestedEmail, ?string $requestedUsername): array
    {
        $email = trim($requestedEmail);
        $username = $requestedUsername === null ? null : trim($requestedUsername);

        $changes = [];

        if ($email !== '' && strcasecmp($email, $user->getEmail()) !== 0) {
            $changes[self::FIELD_EMAIL] = $email;
        }

        if (($username ?? '') !== (string) $user->getUsername()) {
            $changes[self::FIELD_USERNAME] = $username ?? '';
        }

        if ($changes === []) {
            // Nothing to decide. A stale request is withdrawn here so that
            // "put it back the way it was and save" is the obvious undo.
            $this->cancel($user);

            return [];
        }

        $errors = $this->validate($user, $changes);

        if ($errors !== []) {
            return $errors;
        }

        if (!$this->isApprovalRequired()) {
            $this->applyChanges($user, $changes);
            $this->entityManager->flush();

            return [];
        }

        $user->setDataValue(User::DATA_IDENTITY_CHANGE, [
            self::FIELD_EMAIL => $changes[self::FIELD_EMAIL] ?? null,
            self::FIELD_USERNAME => $changes[self::FIELD_USERNAME] ?? null,
            'requested_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $this->entityManager->flush();

        $this->accountMailer->send($user, CoreMailTemplates::IDENTITY_SUBMITTED, [
            'new_email' => $changes[self::FIELD_EMAIL] ?? $user->getEmail(),
            'new_username' => $changes[self::FIELD_USERNAME] ?? (string) $user->getUsername(),
        ]);

        return [];
    }

    /**
     * Applies an outstanding request.
     *
     * Re-validated at this moment, not trusted from when it was filed: an
     * address that was free last week may have been registered since, and the
     * unique index would otherwise turn an approval click into a 500.
     *
     * @return list<string> problems that stopped the approval; empty on success
     */
    public function approve(User $user): array
    {
        $pending = $this->pendingFor($user);

        if ($pending === null) {
            return [$this->translator->trans('account.identity.error.no_request')];
        }

        $changes = [];

        if ($pending['email'] !== null) {
            $changes[self::FIELD_EMAIL] = $pending['email'];
        }

        if ($pending['username'] !== null) {
            $changes[self::FIELD_USERNAME] = $pending['username'];
        }

        $errors = $this->validate($user, $changes);

        if ($errors !== []) {
            return $errors;
        }

        $this->applyChanges($user, $changes);
        $user->removeDataValue(User::DATA_IDENTITY_CHANGE);
        $this->entityManager->flush();

        // Sent to the address that now owns the account, which is the point:
        // if the change was not the member's doing, this is where they find out.
        $this->accountMailer->send($user, CoreMailTemplates::IDENTITY_APPROVED, [
            'new_email' => $user->getEmail(),
            'new_username' => (string) $user->getUsername(),
            'login_url' => $this->accountMailer->loginUrl(),
        ]);

        return [];
    }

    public function reject(User $user, string $reason = ''): bool
    {
        if ($this->pendingFor($user) === null) {
            return false;
        }

        $user->removeDataValue(User::DATA_IDENTITY_CHANGE);
        $this->entityManager->flush();

        $this->accountMailer->send($user, CoreMailTemplates::IDENTITY_REJECTED, [
            'reason' => trim($reason) !== ''
                ? trim($reason)
                : $this->translator->trans('account.identity.default_reject_reason'),
        ]);

        return true;
    }

    /**
     * Withdraws the member's own outstanding request. No mail: they are the one
     * who asked, and they are looking at the screen that did it.
     */
    public function cancel(User $user): bool
    {
        if ($this->pendingFor($user) === null) {
            return false;
        }

        $user->removeDataValue(User::DATA_IDENTITY_CHANGE);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param array<string, string> $changes
     *
     * @return list<string>
     */
    private function validate(User $user, array $changes): array
    {
        $errors = [];

        if (isset($changes[self::FIELD_EMAIL])) {
            $email = $changes[self::FIELD_EMAIL];

            if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->translator->trans('account.register.invalid_email');
            } elseif ($this->users->isEmailTakenByAnotherUser($email, $user->getId())) {
                $errors[] = $this->translator->trans('account.profile.email_taken');
            }
        }

        if (isset($changes[self::FIELD_USERNAME])) {
            $username = $changes[self::FIELD_USERNAME];

            if ($username === '') {
                if ((bool) ($this->settings->get('account.username_required') ?? true)) {
                    $errors[] = $this->translator->trans('account.register.username_required');
                }
            } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
                $errors[] = $this->translator->trans('account.register.username_invalid');
            } elseif ($this->users->isUsernameTakenByAnotherUser($username, $user->getId())) {
                $errors[] = $this->translator->trans('account.register.username_taken');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $changes
     */
    private function applyChanges(User $user, array $changes): void
    {
        if (isset($changes[self::FIELD_EMAIL])) {
            $user->setEmail($changes[self::FIELD_EMAIL]);
        }

        if (isset($changes[self::FIELD_USERNAME])) {
            $username = $changes[self::FIELD_USERNAME];
            $user->setUsername($username !== '' ? $username : null);
        }
    }
}
