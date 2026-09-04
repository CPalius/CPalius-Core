<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Annotation\CpSetting;
use App\Core\Settings\SettingScopeResolver;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Unified settings screen with Core / Modules / Plugins tabs.
 * Persisting is delegated to AACPController::updateSettings (single write path).
 */
final class AACPSettingsController
{
    /** Tab id => translation key, in display order. */
    public const TABS = [
        CpSetting::SCOPE_CORE => 'aacp.settings.tab_core',
        CpSetting::SCOPE_MODULE => 'aacp.settings.tab_modules',
        CpSetting::SCOPE_PLUGIN => 'aacp.settings.tab_plugins',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingScopeResolver $scopeResolver,
    ) {
    }

    #[Route('/aacp/settings', name: 'aacp_settings', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.settings', icon: 'heroicons:cog-6-tooth', panel: 'aacp', priority: 30, capability: 'system.settings.manage', group: 'aacp.group.genadset')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        $definitions = $this->settingsRegistry->all();
        $activeTab = (string) $request->query->get('tab', CpSetting::SCOPE_CORE);

        if (!\array_key_exists($activeTab, self::TABS)) {
            $activeTab = CpSetting::SCOPE_CORE;
        }

        $settingGroups = $this->scopeResolver->groupByScope($definitions, $activeTab);

        $html = $this->twig->render('aacp/settings.html.twig', [
            'tabs' => self::TABS,
            'activeTab' => $activeTab,
            'tabCounts' => $this->scopeResolver->countByScope($definitions),
            'settingGroups' => $settingGroups,
            'currentValues' => $this->currentValues($settingGroups),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_settings')->getValue(),
            'redirectTo' => '/aacp/settings?tab='.$activeTab,
        ]);

        return new Response($html);
    }

    /**
     * Translatable settings are pre-filled with the FULL locale map, never the
     * resolved string, so saving in one panel language cannot overwrite another.
     *
     * @param array<string, list<\App\Core\Settings\SettingDefinition>> $settingGroups
     *
     * @return array<string, mixed>
     */
    private function currentValues(array $settingGroups): array
    {
        $values = [];

        foreach ($settingGroups as $definitions) {
            foreach ($definitions as $definition) {
                $values[$definition->key] = $definition->isTranslatable()
                    ? $this->settingsRegistry->getTranslations($definition->key)
                    : $this->settingsRegistry->get($definition->key);
            }
        }

        return $values;
    }
}
