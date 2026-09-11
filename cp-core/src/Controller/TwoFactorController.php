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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Second-factor challenge and self-service enrollment.
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

        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account_two_factor');

            if ($this->twoFactor->verify($user, (string) $request->request->get('code', ''))) {
                $this->twoFactorSession->markVerified($session);

                return $this->redirectToRoute('account_profile');
            }

            $error = $this->translator->trans('account.two_factor.invalid_code');
        }

        return $this->render('account/two_factor/challenge.html.twig', [
            'error' => $error,
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

        $error = null;
        $recoveryCodes = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account_two_factor_setup');

            $secret = $this->twoFactorSession->pendingSecret($session);
            if ($secret === null) {
                throw new BadRequestHttpException($this->translator->trans('account.two_factor.setup_expired'));
            }

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

            return $this->redirectToRoute('account_profile');
        }

        $this->twoFactor->disable($user);
        $this->twoFactorSession->clear($request->getSession());
        $this->addFlash('success', $this->translator->trans('account.two_factor.disabled'));

        return $this->redirectToRoute('account_profile');
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
