<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Mail\CpMailerService;
use App\Core\Security\Audit\SecurityAuditor;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Session\SessionRegistry;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Live hardening ops surface: posture audit, IP bans and active sessions.
 *
 * Editable security settings (headers, WAF, flood, password, 2FA, session,
 * captcha) live under System Settings → Security.
 */
final class AACPSecurityCenterController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SecurityAuditor $auditor,
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly IpBanService $ipBanService,
        private readonly SessionRegistry $sessionRegistry,
        private readonly TwoFactorService $twoFactor,
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CpMailerService $mailer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/security', name: 'aacp_security_center', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.security.menu', icon: 'heroicons:shield-check', panel: 'aacp', priority: 30, capability: 'system.security.manage')]
    #[IsGranted('system.security.manage')]
    public function index(Request $request): Response
    {
        $findings = $this->auditor->run();
        $threatScannerOn = (bool) $this->settings->get('telemetry.security_enabled', false);
        $activePanel = $request->query->get('panel') === 'findings' ? 'findings' : 'threats';

        return new Response($this->twig->render('aacp/security/index.html.twig', [
            'activePanel' => $activePanel,
            'findings' => $findings,
            'score' => $this->auditor->score($findings),
            'summary' => $this->auditor->summary($findings),
            'bans' => $this->ipBanService->listBans(50),
            'banStats' => $this->ipBanService->stats(),
            'sessions' => $this->sessionRegistry->listActive(50),
            'sessionCount' => $this->sessionRegistry->countActive(),
            'twoFactorEnrolled' => $this->currentUserEnrolled(),
            'loginEmailCodeEnabled' => (bool) $this->settings->get('security.login_email_code', false),
            'mailConfigured' => $this->mailer->canSend(),
            // The audit above is a posture checklist (config recommendations); this
            // is the live feed of what actually happened — who tripped the WAF, with
            // what payload, how often. Previously that data existed (the AACP
            // dashboard's telemetry widget already collected and displayed it) but
            // never here, on the one page named "Security Center" an operator would
            // actually go looking for it.
            'threatScannerOn' => $threatScannerOn,
            'threatFeed' => $threatScannerOn ? $this->telemetryLogRepository->findLiveFeed(25) : [],
            'threatVectors' => $threatScannerOn ? $this->telemetryLogRepository->vectorBreakdown(24) : [],
            'topThreatIps' => $threatScannerOn ? $this->telemetryLogRepository->topThreatIps(10) : [],
            'threatSummary' => $threatScannerOn ? $this->telemetryLogRepository->securitySummary(24) : null,
            'action_token' => $this->csrfTokenManager->getToken('aacp_security_action')->getValue(),
        ]));
    }

    #[Route('/aacp/security/login-email-code', name: 'aacp_security_center_login_email', methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function updateLoginEmailCode(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'aacp_security_action');

        $enabled = $request->request->get('enabled') !== null;
        $existing = $this->settingRepository->findIndexedByKeys(['security.login_email_code']);
        $setting = $existing['security.login_email_code'] ?? null;

        if (!$setting instanceof Setting) {
            $setting = new Setting('security.login_email_code', 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($enabled ? '1' : '0');
        $this->entityManager->flush();
        $this->settings->clearCache('security.login_email_code');

        return new RedirectResponse('/aacp/security?login_email='.($enabled ? 'on' : 'off').'#login-email');
    }

    /**
     * Legacy POST target — settings now save from System Settings → Security.
     */
    #[Route('/aacp/security/update', name: 'aacp_security_center_update', methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function update(): RedirectResponse
    {
        return new RedirectResponse('/aacp/advanced/management?tab=security');
    }

    #[Route('/aacp/security/ban', name: 'aacp_security_center_ban', methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function ban(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'aacp_security_action');

        $pattern = trim((string) $request->request->get('pattern', ''));
        $minutes = (int) $request->request->get('minutes', 0);
        $reason = trim((string) $request->request->get('reason', ''));
        $actor = $this->security->getUser();

        $ok = $this->ipBanService->ban(
            $pattern,
            $actor instanceof User ? $actor->getId() : null,
            $minutes > 0 ? $minutes : null,
            $reason !== '' ? $reason : null,
        );

        return new RedirectResponse('/aacp/security?'.($ok ? 'banned=1' : 'ban_error=1').'#bans');
    }

    #[Route('/aacp/security/unban/{id}', name: 'aacp_security_center_unban', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function unban(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'aacp_security_action');
        $this->ipBanService->unbanById($id);

        return new RedirectResponse('/aacp/security?unbanned=1#bans');
    }

    #[Route('/aacp/security/session/revoke/{id}', name: 'aacp_security_center_revoke_session', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function revokeSession(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'aacp_security_action');
        $this->sessionRegistry->revokeById($id);

        return new RedirectResponse('/aacp/security?session_revoked=1#sessions');
    }

    private function currentUserEnrolled(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->twoFactor->isEnrolled($user);
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }
}
