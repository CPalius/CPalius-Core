<?php

declare(strict_types=1);

namespace Modules\Forum\Admin;

final class ForumDesk
{
    public const TAB_OVERVIEW = 'genel';
    public const TAB_STRUCTURE = 'yapi';
    public const TAB_PERMISSIONS = 'yetkiler';
    public const TAB_MODERATION = 'moderasyon';
    public const TAB_PEOPLE = 'uyeler';
    public const TAB_SETTINGS = 'ayarlar';

    /**
     * @return list<array{id: string, label: string, icon: string, route: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => self::TAB_OVERVIEW, 'label' => 'studio.forum.desk.tab.overview', 'icon' => 'heroicons:squares-2x2', 'route' => 'admin_forum_dashboard'],
            ['id' => self::TAB_STRUCTURE, 'label' => 'studio.forum.desk.tab.structure', 'icon' => 'heroicons:rectangle-group', 'route' => 'admin_forum_sections_index'],
            ['id' => self::TAB_PERMISSIONS, 'label' => 'studio.forum.desk.tab.permissions', 'icon' => 'heroicons:key', 'route' => 'admin_forum_permissions_index'],
            ['id' => self::TAB_MODERATION, 'label' => 'studio.forum.desk.tab.moderation', 'icon' => 'heroicons:shield-check', 'route' => 'admin_forum_moderation_index'],
            ['id' => self::TAB_PEOPLE, 'label' => 'studio.forum.desk.tab.people', 'icon' => 'heroicons:users', 'route' => 'admin_forum_members_index'],
            ['id' => self::TAB_SETTINGS, 'label' => 'studio.forum.desk.tab.settings', 'icon' => 'heroicons:cog-6-tooth', 'route' => 'admin_forum_settings_index'],
        ];
    }

    public static function tabForRoute(string $route): string
    {
        return match (true) {
            str_starts_with($route, 'admin_forum_sections_'),
            str_starts_with($route, 'admin_forum_prefixes_'),
            str_starts_with($route, 'admin_forum_announcements_') => self::TAB_STRUCTURE,
            str_starts_with($route, 'admin_forum_permissions_'),
            str_starts_with($route, 'admin_forum_moderators_') => self::TAB_PERMISSIONS,
            str_starts_with($route, 'admin_forum_moderation_'),
            str_starts_with($route, 'admin_forum_filters_') => self::TAB_MODERATION,
            str_starts_with($route, 'admin_forum_members_'),
            str_starts_with($route, 'admin_forum_ranks_') => self::TAB_PEOPLE,
            str_starts_with($route, 'admin_forum_settings_'),
            str_starts_with($route, 'admin_forum_postbit_'),
            str_starts_with($route, 'admin_forum_stats_') => self::TAB_SETTINGS,
            default => self::TAB_OVERVIEW,
        };
    }
}
