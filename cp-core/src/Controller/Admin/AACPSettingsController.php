<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpSetting;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Legacy /aacp/settings URLs redirect to the unified System Settings screen.
 */
final class AACPSettingsController
{
    private const TAB_MAP = [
        CpSetting::SCOPE_CORE => 'general',
        CpSetting::SCOPE_MODULE => 'modules',
        CpSetting::SCOPE_PLUGIN => 'plugins',
    ];

    private const ALLOWED_TABS = [
        'general', 'email', 'security', 'telemetry', 'registration', 'locales', 'modules', 'plugins',
    ];

    #[Route('/aacp/settings', name: 'aacp_settings', methods: ['GET'])]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): RedirectResponse
    {
        $tab = (string) $request->query->get('tab', 'general');
        $target = self::TAB_MAP[$tab] ?? $tab;
        if (!\in_array($target, self::ALLOWED_TABS, true)) {
            $target = 'general';
        }

        return new RedirectResponse('/aacp/advanced/management?tab='.$target);
    }
}
