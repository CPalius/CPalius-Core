<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Security\Audit\SecurityAuditor;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Session\SessionRegistry;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
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
 * Single management surface for the hardening layer: posture audit, header and
 * WAF policy, flood and password rules, second factor, plus the live ban and
 * session lists.
 *
 * The settings themselves are ordinary #[CpSetting] definitions saved through
 * SystemSettingsService, so validation and persistence behave exactly like every
 * other AACP settings screen; only the presentation is bespoke.
 */
final class AACPSecurityCenterController
{
    private const SECTIONS = [
        'security.headers',
        'security.waf',
        'security.flood',
        'security.password',
        'security.twofactor',
        'security.session',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly SecurityAuditor $auditor,
        private readonly IpBanService $ipBanService,
        private readonly SessionRegistry $sessionRegistry,
        private readonly TwoFactorService $twoFactor,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/security', name: 'aacp_security_center', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.security.menu', icon: 'heroicons:shield-check', panel: 'aacp', priority: 25, capability: 'system.security.manage', group: 'aacp.group.genadset')]
    #[IsGranted('system.security.manage')]
    public function index(): Response
    {
        $definitions = $this->systemSettingsService->definitionsForTab(
            SystemSettingsService::TAB_SECURITY_CENTER,
            $this->settingsRegistry,
        );

        $findings = $this->auditor->run();

        return new Response($this->twig->render('aacp/security/index.html.twig', [
            'sections' => $this->groupIntoSections($definitions),
            'sectionOrder' => self::SECTIONS,
            'values' => $this->systemSettingsService->currentValuesForDefinitions($definitions, $this->settingsRegistry),
            'findings' => $findings,
            'score' => $this->auditor->score($findings),
            'summary' => $this->auditor->summary($findings),
            'bans' => $this->ipBanService->listBans(50),
            'banStats' => $this->ipBanService->stats(),
            'sessions' => $this->sessionRegistry->listActive(50),
            'sessionCount' => $this->sessionRegistry->countActive(),
            'twoFactorEnrolled' => $this->currentUserEnrolled(),
            'csrf_token' => $this->csrfTokenManager->getToken(SystemSettingsService::CSRF_TOKEN_ID)->getValue(),
            'action_token' => $this->csrfTokenManager->getToken('aacp_security_action')->getValue(),
        ]));
    }

    #[Route('/aacp/security/update', name: 'aacp_security_center_update', methods: ['POST'])]
    #[IsGranted('system.security.manage')]
    public function update(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, SystemSettingsService::CSRF_TOKEN_ID);

        /** @var array<string, string|null> $submitted */
        $submitted = $request->request->all('settings');

        $invalidKey = $this->systemSettingsService->updateTab(
            SystemSettingsService::TAB_SECURITY_CENTER,
            $submitted,
            $this->settingsRegistry,
            $this->settingRepository,
            $this->entityManager,
        );

        if ($invalidKey !== null) {
            return new RedirectResponse('/aacp/security?invalid_setting='.urlencode($invalidKey));
        }

        return new RedirectResponse('/aacp/security?saved=1');
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

    /**
     * @param list<\App\Core\Settings\SettingDefinition> $definitions
     *
     * @return array<string, list<\App\Core\Settings\SettingDefinition>>
     */
    private function groupIntoSections(array $definitions): array
    {
        $sections = array_fill_keys(self::SECTIONS, []);

        foreach ($definitions as $definition) {
            $sections[$definition->group][] = $definition;
        }

        return array_filter($sections, static fn (array $group): bool => $group !== []);
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
