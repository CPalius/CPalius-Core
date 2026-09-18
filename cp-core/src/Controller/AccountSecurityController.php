<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Security\Session\SessionRegistry;
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
 * What an account owner can see and do about their own security: which second
 * factor they use, and which devices are still signed in as them.
 *
 * The session list is the half of "clean up idle sessions" that belongs to the
 * member rather than to cron — somebody who signed in on a shared machine and
 * only realised afterwards should not have to wait for a timeout.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountSecurityController extends AbstractController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorSession $twoFactorSession,
        private readonly SessionRegistry $sessionRegistry,
        private readonly SettingsRegistry $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/hesap/guvenlik', name: 'account_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $session = $request->getSession();

        return $this->render('account/security.html.twig', [
            'twoFactorEnabled' => $this->twoFactor->isEnabledGlobally(),
            'enrolled' => $this->twoFactor->isEnrolled($user),
            'method' => $this->twoFactor->method($user),
            'methods' => $this->twoFactor->availableMethods(),
            'mandatory' => $this->twoFactor->isPrivileged($user),
            'verified' => $this->twoFactorSession->isVerified($session),
            'confirmedAt' => $this->twoFactor->confirmedAt($user),
            'recoveryRemaining' => $this->twoFactor->recoveryCodesRemaining($user),
            'sessions' => $this->sessionRegistry->listForUser($user, 50),
            'currentSessionId' => $this->sessionRegistry->idForSession($session->getId()),
            'idleMinutes' => max(0, (int) $this->settings->get('security.session_idle_minutes', 0)),
        ]);
    }

    #[Route('/hesap/guvenlik/oturum/{id}', name: 'account_session_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revokeSession(int $id, Request $request): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'account_sessions');

        $ownSessionId = $this->sessionRegistry->idForSession($request->getSession()->getId());

        if ($ownSessionId === $id) {
            // Revoking the current session from inside it would leave the guard to
            // bounce the visitor on the next request, which reads as a crash.
            // Signing out properly is the same intent, expressed honestly.
            return $this->redirectToRoute('admin_logout');
        }

        $this->sessionRegistry->revokeByIdForUser($id, $user);
        $this->addFlash('success', $this->translator->trans('account.security.session_revoked'));

        return $this->redirectToRoute('account_security');
    }

    #[Route('/hesap/guvenlik/oturumlar', name: 'account_sessions_revoke_all', methods: ['POST'])]
    public function revokeOtherSessions(Request $request): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'account_sessions');

        $count = $this->sessionRegistry->revokeAllForUser($user, $request->getSession()->getId());
        $this->addFlash('success', $this->translator->trans('account.security.sessions_revoked', ['count' => $count]));

        return $this->redirectToRoute('account_security');
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
}
