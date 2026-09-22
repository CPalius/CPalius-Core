<?php

declare(strict_types=1);

namespace Modules\Ai\Attribute;

/**
 * Marks a Studio action as an AI settings card. The settings screen is the hub;
 * cards stay off the sidebar.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AiSettingsCard
{
    /**
     * @param string $label       translation key for the card title
     * @param string $description translation key for the card subtitle
     * @param string $icon        symfony/ux-icons name
     */
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly string $icon = 'heroicons:sparkles',
        public readonly int $priority = 100,
    ) {
    }
}
