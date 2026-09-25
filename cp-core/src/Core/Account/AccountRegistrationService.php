<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Localization\Service\UserLocaleResolver;
use App\Core\Mail\Template\CoreMailTemplates;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CPalius equivalent of MegaforBB AuthService::register() and AdminUserSettings flow.
 */
final class AccountRegistrationService
{
    public const FIELD_HIDDEN = '0';
    public const FIELD_OPTIONAL = '1';
    public const FIELD_REQUIRED = '2';

    /**
     * How long an e-mail verification link stays valid. 48 hours covers a
     * weekend and a slow mail queue, which is the longest a genuine user
     * plausibly takes; past that, re-registering is the shorter path anyway.
     */
    private const VERIFICATION_TOKEN_TTL = 172800;

    /** Allowed name chars (SEC-03): \p{L}/\p{M}, space, apostrophe, hyphen, period. See validateNameField(). */
    private const NAME_PATTERN = "/^[\p{L}\p{M}\x{0020}\x{00A0}'\x{2019}.\-]+$/u";

    /** Product limit (not a DB column cap): 60 chars covers long real names and prevents template overflow. */
    private const NAME_MAX_LENGTH = 60;

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly AccountMailer $accountMailer,
        private readonly UserLocaleResolver $userLocale,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
    }

    public function isRegistrationEnabled(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.registration_enabled') ?? true);
    }

    public function isEmailVerificationRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_email_verification') ?? false);
    }

    /**
     * Whether an unverified account is refused login — independent of
     * isEmailVerificationRequired(), which only governs the registration
     * flow. AccountUserChecker::checkPostAuth() requires both this AND
     * isEmailVerificationRequired() before blocking a login: if
     * registration itself never asked for verification, an account being
     * "unverified" carries no meaning to gate login on.
     */
    public function isLoginVerificationRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_email_verification_at_login') ?? true);
    }

    public function isAdminApprovalRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_admin_approval') ?? false);
    }

    public function isUsernameRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.username_required') ?? true);
    }

    public function isTermsRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_terms') ?? true);
    }

    public function firstNameMode(): string
    {
        return (string) ($this->settingsRegistry->get('account.show_first_name') ?? self::FIELD_REQUIRED);
    }

    public function lastNameMode(): string
    {
        return (string) ($this->settingsRegistry->get('account.show_last_name') ?? self::FIELD_REQUIRED);
    }

    /**
     * @return array{email: string, username: string, firstName: string, lastName: string, locale: string}
     */
    public function defaultFormValues(): array
    {
        return [
            'email' => '',
            'username' => '',
            'firstName' => '',
            'lastName' => '',
            // The site default, not a hard-coded 'tr': the form offers whatever
            // locales are active, and pre-selecting a code that is switched off
            // would make the first option look chosen when it is not.
            'locale' => $this->userLocale->resolve(null),
        ];
    }

    /**
     * @param array{email: string, username: string, firstName: string, lastName: string, password: string, passwordConfirm: string, termsAccepted: bool, locale: string} $input
     *
     * @return string[]
     */
    public function validate(array $input): array
    {
        $errors = [];
        $email = trim($input['email']);
        $username = trim($input['username']);
        $password = $input['password'];
        $passwordConfirm = $input['passwordConfirm'];

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('account.register.invalid_email');
        } elseif ($this->userRepository->isEmailTakenByAnotherUser($email, null)) {
            $errors[] = $this->translator->trans('account.register.email_taken');
        }

        if ($this->isUsernameRequired() && $username === '') {
            $errors[] = $this->translator->trans('account.register.username_required');
        } elseif ($username !== '') {
            if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
                $errors[] = $this->translator->trans('account.register.username_invalid');
            } elseif ($this->userRepository->findOneByUsername($username) !== null) {
                $errors[] = $this->translator->trans('account.register.username_taken');
            }
        }

        $errors = array_merge($errors, $this->validateNameField('firstName', $this->firstNameMode(), trim($input['firstName'])));
        $errors = array_merge($errors, $this->validateNameField('lastName', $this->lastNameMode(), trim($input['lastName'])));

        if ($password !== $passwordConfirm) {
            $errors[] = $this->translator->trans('account.register.password_mismatch');
        } else {
            $errors = array_merge($errors, $this->passwordPolicy->validate($password, [
                $email,
                $username,
                trim($input['firstName']),
                trim($input['lastName']),
            ]));
        }

        if ($this->isTermsRequired() && !$input['termsAccepted']) {
            $errors[] = $this->translator->trans('account.register.terms_required');
        }

        return $errors;
    }

    public function applyPostRegistrationState(User $user): bool
    {
        $needsDefer = false;

        if ($this->isAdminApprovalRequired()) {
            $user->setStatus(User::STATUS_INACTIVE);
            $user->setDataValue('registration_pending_approval', true);
            $needsDefer = true;
        }

        if ($this->isEmailVerificationRequired()) {
            $user->setEmailVerificationToken($this->generateToken());
            $needsDefer = true;
        } else {
            $user->markEmailVerified();
        }

        if ($needsDefer) {
            $this->entityManager->flush();
        }

        return $needsDefer;
    }

    /**
     * Accepts a verification token only within its TTL. Tokens with no issued_at are refused.
     */
    public function verifyEmailByToken(string $token): ?User
    {
        $user = $this->userRepository->findOneByEmailVerificationToken($token);
        if ($user === null) {
            return null;
        }

        $issuedAt = $user->getEmailVerificationIssuedAt();
        if ($issuedAt === null || time() - $issuedAt->getTimestamp() > self::VERIFICATION_TOKEN_TTL) {
            return null;
        }

        $user->markEmailVerified();
        $this->entityManager->flush();

        return $user;
    }

    public function approveUser(User $user): void
    {
        $user->setStatus(User::STATUS_ACTIVE);
        $user->markRegistrationApproved();
        $this->entityManager->flush();

        $this->sendAccountMail($user, CoreMailTemplates::ACCOUNT_APPROVED, [
            'login_url' => $this->accountMailer->loginUrl(),
        ]);
    }

    public function buildVerificationUrl(User $user): ?string
    {
        $token = $user->getEmailVerificationToken();
        if ($token === null || $token === '') {
            return null;
        }

        return $this->urlGenerator->generate('account_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function sendVerificationEmail(User $user): bool
    {
        if (!$this->isEmailVerificationRequired()) {
            return false;
        }

        $url = $this->buildVerificationUrl($user);
        if ($url === null) {
            return false;
        }

        return $this->sendAccountMail($user, CoreMailTemplates::ACCOUNT_VERIFY, ['url' => $url]);
    }

    /**
     * Sent once registration has completed and the member can log in straight
     * away. Not sent when verification or approval is pending — those states get
     * their own mail, and a "welcome, you're in" note while the account is still
     * locked is the kind of contradiction support tickets are made of.
     */
    public function sendWelcomeEmail(User $user): bool
    {
        return $this->sendAccountMail($user, CoreMailTemplates::ACCOUNT_WELCOME, [
            'login_url' => $this->accountMailer->loginUrl(),
        ]);
    }

    public function sendPendingApprovalEmail(User $user): bool
    {
        return $this->sendAccountMail($user, CoreMailTemplates::ACCOUNT_PENDING_APPROVAL);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function sendAccountMail(User $user, string $templateKey, array $parameters = []): bool
    {
        return $this->accountMailer->send($user, $templateKey, $parameters);
    }

    /**
     * Validates first/last name fields (audit SEC-03): length, charset, blocks HTML injection at registration.
     * First defense layer; template escaping is the second (existing denormalized names are not retroactively cleaned).
     *
     * @return string[]
     */
    private function validateNameField(string $field, string $mode, string $value): array
    {
        if ($mode === self::FIELD_HIDDEN) {
            return [];
        }

        $isFirstName = $field === 'firstName';

        if ($value === '') {
            if ($mode === self::FIELD_REQUIRED) {
                return [$this->translator->trans(
                    $isFirstName ? 'account.register.first_name_required' : 'account.register.last_name_required',
                )];
            }

            // Optional and empty: nothing to validate.
            return [];
        }

        $errors = [];

        if (mb_strlen($value) > self::NAME_MAX_LENGTH) {
            $errors[] = $this->translator->trans(
                $isFirstName ? 'account.register.first_name_too_long' : 'account.register.last_name_too_long',
            );
        }

        // "u" flag required: without it \p{L} fails and multibyte chars are matched per byte.
        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            $errors[] = $this->translator->trans(
                $isFirstName ? 'account.register.first_name_invalid' : 'account.register.last_name_invalid',
            );
        }

        return $errors;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
