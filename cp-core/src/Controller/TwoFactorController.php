<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Security\TwoFactor\TwoFactorGuardSubscriber;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Security\TwoFactor\TwoFactorSession;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Second-factor challenge and self-service enrollment, for both methods: an
 * authenticator app, or a code e-mailed at sign-in.
 *
 * These routes are exempt from TwoFactorGuardSubscriber, so each one re-checks the
 * session state itself instead of trusting that the guard already did.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorSession $twoFactorSession,
        private readonly SettingsRegistry $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(TwoFactorGuardSubscriber::CHALLENGE_PATH, name: 'account_two_factor_challenge', methods: ['GET', 'POST'])]
    public function challenge(Request $request): Response
    {
        $user = $this->currentUser();
        $session = $request->getSession();

        if (!$this->twoFactor->isEnrolled($user)) {
            return $this->redirectToRoute('account_profile');
        }

        if ($this->twoFactorSession->isVerified($session)) {
            return $this->redirectToRoute('account_profile');
        }

        $usesEmail = $this->twoFactor->usesEmail($user);
        $error = null;
        $notice = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account_two_factor');

            if ($usesEmail && $request->request->get('action') === 'resend') {
                [$notice, $error] = $this->resend($request, $user);
            } elseif ($this->twoFactor->verify($user, (string) $request->request->get('code', ''))) {
                $this->twoFactorSession->markVerified($session);

                return $this->redirectToRoute('account_profile');
            } else {
                $error = $this->translator->trans('account.two_factor.invalid_code');
            }
        }

        // First arrival on an e-mail account: send the code without making the
        // visitor ask for it. A reload inside the code's lifetime does not
        // trigger another one — that is what hasPendingEmailCode() is for.
        if ($usesEmail && $error === null && !$this->twoFactor->hasPendingEmailCode($user)) {
            $sent = $this->twoFactor->issueEmailCode($user, $request);

            if ($sent) {
                $notice = $this->translator->trans('account.two_factor.email_sent', ['minutes' => $this->twoFactor->emailCodeMinutes()]);
            } else {
                $error = $this->translator->trans('account.two_factor.email_send_failed');
            }
        }

        return $this->render('account/two_factor/challenge.html.twig', [
            'error' => $error,
            'notice' => $notice,
            'usesEmail' => $usesEmail,
            'maskedEmail' => $usesEmail ? $this->maskEmail($user->getEmail()) : '',
            'resendWait' => $usesEmail ? $this->twoFactor->emailResendWait($user) : 0,
            'recoveryRemaining' => $this->twoFactor->recoveryCodesRemaining($user),
        ]);
    }

    #[Route(TwoFactorGuardSubscriber::SETUP_PATH, name: 'account_two_factor_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $user = $this->currentUser();
        $session = $request->getSession();

        if (!$this->twoFactor->isEnabledGlobally()) {
            throw new AccessDeniedHttpException($this->translator->trans('account.two_factor.disabled_site_wide'));
        }

        // Changing an existing second factor requires passing the current one first,
        // or a stolen session could quietly swap the authenticator out.
        if ($this->twoFactor->isEnrolled($user) && !$this->twoFactorSession->isVerified($session)) {
            return $this->redirectToRoute('account_two_factor_challenge');
        }

        $available = $this->twoFactor->availableMethods();
        $error = null;
        $notice = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account_two_factor_setup');
            $action = (string) $request->request->get('action', 'confirm');

            if ($action === 'method') {
                $chosen = (string) $request->request->get('method', '');

                if (!\in_array($chosen, $available, true)) {
                    $error = $this->translator->trans('account.two_factor.method_unavailable');
                } elseif (!$this->startEnrollment($user, $chosen, $session, $request)) {
                    $error = $this->translator->trans('account.two_factor.email_send_failed');
                } else {
                    // Redirect after the state change so a reload does not repeat it.
                    return $this->redirectToRoute('account_two_factor_setup');
                }
            } elseif ($action === 'restart') {
                $this->twoFactorSession->clearPending($session);

                return $this->redirectToRoute('account_two_factor_setup');
            } elseif ($action === 'resend') {
                [$notice, $error] = $this->resend($request, $user);
            } else {
                $recoveryCodes = $this->twoFactor->confirmEnrollment($user, (string) $request->request->get('code', ''));

                if ($recoveryCodes === null) {
                    $error = $this->translator->trans('account.two_factor.invalid_code');
                } else {
                    $this->twoFactorSession->markVerified($session);

                    return $this->render('account/two_factor/recovery_codes.html.twig', [
                        'recoveryCodes' => $recoveryCodes,
                    ]);
                }
            }
        }

        $method = $this->twoFactorSession->pendingMethod($session);

        // Nothing picked yet. One available method means there is nothing to ask.
        if ($method === null || !\in_array($method, $available, true)) {
            $method = null;

            if (\count($available) === 1 && $this->startEnrollment($user, $available[0], $session, $request)) {
                $method = $available[0];
            }

            if ($method === null) {
                return $this->render('account/two_factor/method.html.twig', [
                    'error' => $error,
                    'methods' => $available,
                    'mandatory' => $this->twoFactor->isPrivileged($user),
                    'maskedEmail' => $this->maskEmail($user->getEmail()),
                    'minutes' => $this->twoFactor->emailCodeMinutes(),
                ]);
            }
        }

        if ($method === TwoFactorService::METHOD_EMAIL) {
            return $this->render('account/two_factor/setup_email.html.twig', [
                'error' => $error,
                'notice' => $notice,
                'maskedEmail' => $this->maskEmail($user->getEmail()),
                'minutes' => $this->twoFactor->emailCodeMinutes(),
                'resendWait' => $this->twoFactor->emailResendWait($user),
                'canSwitch' => \count($available) > 1,
                'mandatory' => $this->twoFactor->isPrivileged($user),
            ]);
        }

        $secret = $this->twoFactorSession->pendingSecret($session);
        if ($secret === null) {
            $secret = $this->twoFactor->beginEnrollment($user);
            $this->twoFactorSession->setPendingSecret($session, $secret);
        }

        return $this->render('account/two_factor/setup.html.twig', [
            'error' => $error,
            'secret' => $secret,
            'provisioningUri' => $this->twoFactor->provisioningUri($user, $secret, $this->issuer()),
            'mandatory' => $this->twoFactor->isPrivileged($user),
            'canSwitch' => \count($available) > 1,
        ]);
    }

    #[Route('/hesap/iki-adimli-kapat', name: 'account_two_factor_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'account_two_factor_disable');

        if (!$this->twoFactorSession->isVerified($request->getSession())) {
            return $this->redirectToRoute('account_two_factor_challenge');
        }

        if ($this->twoFactor->isPrivileged($user)) {
            $this->addFlash('error', $this->translator->trans('account.two_factor.mandatory_cannot_disable'));

            return $this->redirectToRoute('account_security');
        }

        $this->twoFactor->disable($user);
        $this->twoFactorSession->clear($request->getSession());
        $this->addFlash('success', $this->translator->trans('account.two_factor.disabled'));

        return $this->redirectToRoute('account_security');
    }

    #[Route('/hesap/iki-adimli-kurtarma-kodlari', name: 'account_two_factor_recovery_codes', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'account_two_factor_recovery');

        if (!$this->twoFactor->isEnrolled($user) || !$this->twoFactorSession->isVerified($request->getSession())) {
            return $this->redirectToRoute('account_two_factor_challenge');
        }

        return $this->render('account/two_factor/recovery_codes.html.twig', [
            'recoveryCodes' => $this->twoFactor->regenerateRecoveryCodes($user),
        ]);
    }

    /**
     * Writes the chosen method onto the account and gets its first code moving.
     */
    private function startEnrollment(User $user, string $method, SessionInterface $session, Request $request): bool
    {
        if ($method === TwoFactorService::METHOD_EMAIL) {
            if (!$this->twoFactor->beginEmailEnrollment($user, $request)) {
                return false;
            }

            $this->twoFactorSession->setPendingMethod($session, TwoFactorService::METHOD_EMAIL);

            return true;
        }

        $this->twoFactorSession->setPendingSecret($session, $this->twoFactor->beginEnrollment($user));
        $this->twoFactorSession->setPendingMethod($session, TwoFactorService::METHOD_TOTP);

        return true;
    }

    /**
     * @return array{0: string|null, 1: string|null} notice, then error
     */
    private function resend(Request $request, User $user): array
    {
        $wait = $this->twoFactor->emailResendWait($user);

        if ($wait > 0) {
            return [null, $this->translator->trans('account.two_factor.resend_wait', ['seconds' => $wait])];
        }

        if (!$this->twoFactor->issueEmailCode($user, $request)) {
            return [null, $this->translator->trans('account.two_factor.email_send_failed')];
        }

        return [$this->translator->trans('account.two_factor.email_sent', ['minutes' => $this->twoFactor->emailCodeMinutes()]), null];
    }

    /**
     * Shows enough of the address to recognise the mailbox, not enough to learn
     * one: the challenge screen is reachable with a stolen password alone.
     */
    private function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).$domain;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }
    }

    private function issuer(): string
    {
        $name = trim((string) ($this->settings->get('core.site_name') ?? ''));

        return $name !== '' ? $name : 'CPalius';
    }
}
