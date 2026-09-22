<?php

declare(strict_types=1);

namespace Modules\Ai\Twig;

use App\Core\Settings\SettingsRegistry;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes ai_auto_translate so Forum/Blog/Pages forms can show the checkbox only
 * when this module is loaded — no hard import in those templates' PHP.
 */
final class AiExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    /**
     * @return array<string, array{available: bool, on_create: bool, on_edit: bool}>
     */
    public function getGlobals(): array
    {
        $enabled = (string) $this->settings->get('ai.enabled', '1') === '1';

        return [
            'ai_auto_translate' => [
                'available' => true,
                'on_create' => $enabled,
                'on_edit' => $enabled && (string) $this->settings->get('ai.allow_edit_translate', '0') === '1',
            ],
        ];
    }
}
