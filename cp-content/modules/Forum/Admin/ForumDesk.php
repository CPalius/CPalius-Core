<?php

declare(strict_types=1);

namespace Modules\Forum\Admin;

final class ForumDesk
{
    public const TAB_OVERVIEW = 'genel';
    public const TAB_STRUCTURE = 'yapi';
    public const TAB_MODERATION = 'moderasyon';
    public const TAB_MEMBERS = 'uyeler';
    public const TAB_ENGINE = 'motor';

    /**
     * @return list<array{id: string, label: string, icon: string, route: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => self::TAB_OVERVIEW, 'label' => 'studio.forum.desk.tab.overview', 'icon' => 'heroicons:squares-2x2', 'route' => 'admin_forum_dashboard'],
            ['id' => self::TAB_STRUCTURE, 'label' => 'studio.forum.desk.tab.structure', 'icon' => 'heroicons:rectangle-group', 'route' => 'admin_forum_sections_index'],
            ['id' => self::TAB_MODERATION, 'label' => 'studio.forum.desk.tab.moderation', 'icon' => 'heroicons:shield-check', 'route' => 'admin_forum_moderation_index'],
            ['id' => self::TAB_MEMBERS, 'label' => 'studio.forum.desk.tab.members', 'icon' => 'heroicons:users', 'route' => 'admin_forum_members_index'],
            ['id' => self::TAB_ENGINE, 'label' => 'studio.forum.desk.tab.engine', 'icon' => 'heroicons:cog-6-tooth', 'route' => 'admin_forum_settings_index'],
        ];
    }

    public static function tabForRoute(string $route): string
    {
        return match (true) {
            str_starts_with($route, 'admin_forum_sections_'),
            str_starts_with($route, 'admin_forum_prefixes_'),
            str_starts_with($route, 'admin_forum_permissions_') => self::TAB_STRUCTURE,
            str_starts_with($route, 'admin_forum_moderation_') => self::TAB_MODERATION,
            str_starts_with($route, 'admin_forum_members_'),
            str_starts_with($route, 'admin_forum_ranks_') => self::TAB_MEMBERS,
            str_starts_with($route, 'admin_forum_settings_') => self::TAB_ENGINE,
            default => self::TAB_OVERVIEW,
        };
    }
}
