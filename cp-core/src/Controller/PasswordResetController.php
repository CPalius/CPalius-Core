<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Account\AccountMailer;
use App\Core\Mail\Template\CoreMailTemplates;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Security\Session\SessionRegistry;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Forgot password": a one-hour, single-use link mailed to the account's address.
 *
 * The request screen answers identically whether or not the address exists, so
 * it cannot be used to discover who has an account. Only the token's SHA-256 is
 * stored. A completed reset signs the account out everywhere and does not log
 * in — the member signs in normally, so two-factor still applies.
 */
final class PasswordResetController extends AbstractController
{
    private const TOKEN_TTL = 3600;
    private const IP_LIMIT = 10;
    private const EMAIL_LIMIT = 3;
    private const WINDOW = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $mailer,
        private readonly PasswordPolicy $policy,
        private readonly PasswordChanger $passwordChanger,
        private readonly SessionRegistry $sessions,
        private readonly FloodService $flood,
        private readonly SettingsRegistry $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/hesap/sifremi-unuttum', name: 'account_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        $sent = false;
        $error = null;
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));

        if ($request->isMethod('POST')) {
            $ip = (string) ($request->getClientIp() ?? '');
            if (!$this->isCsrfTokenValid('password_forgot', (string) $request->request->get('_token'))) {
                $error = $this->translator->trans('account.invalid_csrf');
            } elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $error = $this->translator->trans('account.password_reset.invalid_email');
            } elseif (!$this->allowed('ip:'.$ip, self::IP_LIMIT) || !$this->allowed('email:'.$email, self::EMAIL_LIMIT)) {
                $error = $this->translator->trans('account.password_reset.too_many');
            } else {
                $user = $this->users->findOneBy(['email' => $email]);
                if ($user instanceof User && $user->getStatus() === User::STATUS_ACTIVE) {
                    $this->issue($user, $ip);
                }
                $sent = true;
            }
        }

        return $this->render('admin/password_forgot.html.twig', [
            'siteName' => $this->siteName($request),
            'email' => $email,
            'sent' => $sent,
            'error' => $error,
        ]);
    }

    #[Route('/hesap/sifre-sifirla/{token}', name: 'account_password_reset', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,128}'])]
    public function reset(Request $request, string $token): Response
    {
        $user = $this->userForToken($token);
        $errors = [];

        if ($user instanceof User && $request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            if (!$this->isCsrfTokenValid('password_reset', (string) $request->request->get('_token'))) {
                $errors[] = $this->translator->trans('account.invalid_csrf');
            } elseif ($password !== (string) $request->request->get('password_confirm', '')) {
                $errors[] = $this->translator->trans('account.password_reset.mismatch');
            } else {
                $errors = $this->policy->validate($password, $this->passwordChanger->identityOf($user), $user);
            }

            if ($errors === []) {
                $this->passwordChanger->change($user, $password);
                $user->removeDataValue('password_reset_token_hash');
                $user->removeDataValue('password_reset_issued_at');
                $this->entityManager->flush();
                $this->sessions->revokeAllForUser($user);

                $this->addFlash('success', $this->translator->trans('account.password_reset.done'));

                return $this->redirectToRoute('admin_login');
            }
        }

        return $this->render('admin/password_reset.html.twig', [
            'siteName' => $this->siteName($request),
            'valid' => $user instanceof User,
            'token' => $token,
            'errors' => $errors,
            'minLength' => $this->policy->minLength(),
        ]);
    }

    private function issue(User $user, string $ip): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $user->setDataValue('password_reset_token_hash', hash('sha256', $token));
        $user->setDataValue('password_reset_issued_at', (new \DateTimeImmutable())->format(\DATE_ATOM));
        $this->entityManager->flush();

        $this->mailer->send($user, CoreMailTemplates::ACCOUNT_PASSWORD_RESET, [
            'url' => $this->generateUrl('account_password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'minutes' => (int) (self::TOKEN_TTL / 60),
            'ip' => $ip,
        ], true);
    }

    private function userForToken(string $token): ?User
    {
        $user = $this->users->findOneByPasswordResetTokenHash(hash('sha256', $token));
        if (!$user instanceof User || $user->getStatus() !== User::STATUS_ACTIVE) {
            return null;
        }

        $issuedAt = strtotime((string) $user->getDataValue('password_reset_issued_at', ''));

        return $issuedAt !== false && time() - $issuedAt <= self::TOKEN_TTL ? $user : null;
    }

    /**
     * Every request counts, sent or not, so probing addresses costs the same as using the form.
     */
    private function allowed(string $identifier, int $limit): bool
    {
        if (!$this->flood->isAllowed(FloodService::EVENT_PASSWORD_RESET, $identifier, $limit, self::WINDOW)) {
            return false;
        }
        $this->flood->register(FloodService::EVENT_PASSWORD_RESET, $identifier, self::WINDOW);

        return true;
    }

    private function siteName(Request $request): string
    {
        $name = trim((string) $this->settings->getForLocale('core.site_name', $request->getLocale(), ''));

        return $name !== '' ? $name : 'CPalius CMF';
    }
}
