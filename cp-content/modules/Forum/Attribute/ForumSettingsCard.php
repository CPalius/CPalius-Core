<?php

declare(strict_types=1);

namespace Modules\Forum\Attribute;

/**
 * Marks a Studio controller action as a card on the settings hub (/admin/forum/settings).
 * Collected at compile time by ForumSettingsCardPass — never a sidebar entry.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ForumSettingsCard
{
    /**
     * @param string      $label       translation key for the card title
     * @param string      $description translation key for the card subtitle
     * @param string      $icon        symfony/ux-icons name
     * @param string      $group       translation key for the shelf heading on the hub
     * @param int         $priority    lower values sort first
     * @param string|null $capability  required capability; null = forum.section.manage
     */
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly string $icon = 'heroicons:cog-6-tooth',
        public readonly string $group = 'studio.forum.settings.hub.board',
        public readonly int $priority = 100,
        public readonly ?string $capability = null,
    ) {
    }
}
