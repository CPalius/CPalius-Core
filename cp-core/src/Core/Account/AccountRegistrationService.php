<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Mail\CpMailerService;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Settings\SettingsRegistry;
use App\Core\Token\TokenReplacer;
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
        private readonly CpMailerService $mailerService,
        private readonly TokenReplacer $tokenReplacer,
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
            'locale' => 'tr',
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

    public function verifyEmailByToken(string $token): ?User
    {
        $user = $this->userRepository->findOneByEmailVerificationToken($token);
        if ($user === null) {
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
        if ($url === null || !$this->mailerService->canSend()) {
            return false;
        }

        try {
            // T2.4: translation catalog strings may additionally contain
            // [user:display_name]/[site:name]-style tokens; a no-op for today's
            // catalog values (no brackets in them), real once one is edited to add some.
            $tokenContext = ['user' => $user];

            $this->mailerService->sendHtml(
                $user->getEmail(),
                $this->tokenReplacer->replace($this->translator->trans('account.verify.email_subject'), $tokenContext, true),
                $this->tokenReplacer->replace(
                    $this->translator->trans('account.verify.email_body_html', ['url' => $url]),
                    $tokenContext,
                    true,
                ),
                $this->tokenReplacer->replace($this->translator->trans('account.verify.email_body_text', ['url' => $url]), $tokenContext),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
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
