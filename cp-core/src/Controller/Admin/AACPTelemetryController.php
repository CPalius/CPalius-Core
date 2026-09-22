<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Security\Http\LoginTargetPath;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\IpBanService;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Entity\User;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * Live telemetry feed, IP bans, and retention settings for the AACP console.
 */
final class AACPTelemetryController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly IpBanService $ipBanService,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/telemetry/live-feed', name: 'aacp_telemetry_live_feed', methods: ['GET'])]
    #[IsGranted('system.aacp.access')]
    public function liveFeed(Request $request): Response
    {
        if (LoginTargetPath::isBrowserDocument($request)) {
            return new RedirectResponse('/aacp');
        }

        $afterId = $request->query->getInt('after_id', 0);
        $after = $afterId > 0 ? $afterId : null;
        $securityOn = (bool) $this->settingsRegistry->get('telemetry.security_enabled', false);

        if ($securityOn) {
            return new JsonResponse([
                'mode' => 'security',
                'rows' => $this->telemetryLogRepository->findLiveFeed(40, $after),
                'trend' => $this->telemetryLogRepository->hourlyTrend(24),
                'vectors' => $this->telemetryLogRepository->vectorBreakdown(24),
                'topIps' => $this->telemetryLogRepository->topThreatIps(5),
            ]);
        }

        $stats = $this->telemetryLogRepository->visitorStats(24);

        return new JsonResponse([
            'mode' => 'visitors',
            'rows' => $this->telemetryLogRepository->findLiveFeed(40, $after, true),
            'trend' => $stats['hourly'],
            'topPages' => $stats['topPages'],
            'topIps' => $stats['topIps'],
            'uniqueIps' => $stats['uniqueIps'],
            'pageViews' => $stats['pageViews'],
        ]);
    }

    #[Route('/aacp/telemetry/ban-ip', name: 'aacp_telemetry_ban_ip', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function banIp(Request $request): JsonResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_telemetry_ban_ip', $token))) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        $ip = trim((string) $request->request->get('ip', ''));
        $user = $this->security->getUser();
        $bannedBy = $user instanceof User ? $user->getId() : null;
        $ok = $this->ipBanService->ban($ip, $bannedBy);

        return new JsonResponse([
            'success' => $ok,
            'ip' => $ip,
            'message' => $ok
                ? $this->translator->trans('aacp.telemetry.ban.ok', ['ip' => $ip])
                : $this->translator->trans('aacp.telemetry.ban.fail'),
        ], $ok ? 200 : 400);
    }

    #[Route('/aacp/settings/telemetry', name: 'aacp_telemetry_settings', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.telemetry', icon: 'heroicons:signal', panel: 'aacp', priority: 31, capability: 'system.aacp.access', parent: 'aacp_security_center')]
    #[IsGranted('system.aacp.access')]
    public function settings(): Response
    {
        $definitions = $this->systemSettingsService->definitionsForTab('telemetry', $this->settingsRegistry);
        $values = $this->systemSettingsService->currentValuesForDefinitions($definitions, $this->settingsRegistry);

        $html = $this->twig->render('aacp/telemetry_settings.html.twig', [
            'definitions' => $definitions,
            'values' => $values,
            'csrf_token' => $this->csrfTokenManager->getToken(SystemSettingsService::CSRF_TOKEN_ID)->getValue(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/settings/telemetry', name: 'aacp_telemetry_settings_update', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function updateSettings(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SystemSettingsService::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        /** @var array<string, string|null> $submitted */
        $submitted = $request->request->all('settings');
        $invalidKey = $this->systemSettingsService->updateTab(
            'telemetry',
            $submitted,
            $this->settingsRegistry,
            $this->settingRepository,
            $this->entityManager,
        );

        $redirect = '/aacp/settings/telemetry';
        if ($invalidKey !== null) {
            return new RedirectResponse($redirect.'?invalid_setting='.urlencode($invalidKey));
        }

        return new RedirectResponse($redirect.'?saved=1');
    }
}
