<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Mail\CpMailerService;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
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
 * Phase 5 placeholder routes with stable names; bodies swap to real features later.
 * Protected by system.aacp.access until dedicated capabilities exist.
 */
final class AACPPlaceholderController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly CpMailerService $mailerService,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LocaleRepository $localeRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Single AACP settings screen: core tabs plus module/plugin settings.
     */
    #[Route('/aacp/advanced/management', name: 'aacp_advanced_management', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.system_management', icon: 'heroicons:cog-6-tooth', panel: 'aacp', priority: 24, capability: 'system.aacp.access', parent: 'aacp_hub_system')]
    #[IsGranted('system.aacp.access')]
    public function advancedManagement(Request $request): Response
    {
        $activeTab = (string) $request->query->get('tab', 'general');
        if (!array_key_exists($activeTab, $this->systemSettingsService->tabs())) {
            $activeTab = 'general';
        }

        $tabDefinitions = [];
        $tabValues = [];
        $tabGroups = [];
        foreach ($this->systemSettingsService->tabs() as $tabId => $tabMeta) {
            if (($tabMeta['isLocales'] ?? false) === true) {
                continue;
            }
            $definitions = $this->systemSettingsService->definitionsForTab($tabId, $this->settingsRegistry);
            $tabDefinitions[$tabId] = $definitions;
            $tabValues[$tabId] = $this->systemSettingsService->currentValuesForDefinitions($definitions, $this->settingsRegistry);
            if (($tabMeta['grouped'] ?? false) === true) {
                $tabGroups[$tabId] = $this->systemSettingsService->groupDefinitions($definitions);
            }
        }

        $html = $this->twig->render('aacp/management.html.twig', [
            'tabs' => $this->systemSettingsService->tabs(),
            'activeTab' => $activeTab,
            'tabDefinitions' => $tabDefinitions,
            'tabValues' => $tabValues,
            'tabGroups' => $tabGroups,
            'locales' => $this->localeRepository->findBy([], ['sortOrder' => 'ASC']),
            'csrf_token' => $this->csrfTokenManager->getToken(SystemSettingsService::CSRF_TOKEN_ID)->getValue(),
            'locales_csrf_token' => $this->csrfTokenManager->getToken('aacp_locales')->getValue(),
            'mailConfigured' => $this->mailerService->canSend(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/advanced/management/update', name: 'aacp_advanced_management_update', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function updateCoreSettings(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SystemSettingsService::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $tab = (string) $request->request->get('_tab', 'general');
        if (!array_key_exists($tab, $this->systemSettingsService->tabs())) {
            $tab = 'general';
        }

        /** @var array<string, string|null> $submitted */
        $submitted = $request->request->all('settings');

        $invalidKey = $this->systemSettingsService->updateTab(
            $tab,
            $submitted,
            $this->settingsRegistry,
            $this->settingRepository,
            $this->entityManager,
        );

        $redirect = '/aacp/advanced/management?tab='.$tab;
        if ($invalidKey !== null) {
            return new RedirectResponse($redirect.'&invalid_setting='.urlencode($invalidKey));
        }

        return new RedirectResponse($redirect.'&saved=1');
    }

    #[Route('/aacp/advanced/management/test-email', name: 'aacp_advanced_management_test_email', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function testEmail(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SystemSettingsService::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $to = trim((string) $request->request->get('test_email'));
        $tab = 'email';

        if ($to === '' || !filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=invalid');
        }

        if (!$this->mailerService->canSend()) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=not_configured');
        }

        try {
            $this->mailerService->sendTestEmail($to);
        } catch (\Throwable) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=send_failed');
        }

        return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_sent=1');
    }
}
