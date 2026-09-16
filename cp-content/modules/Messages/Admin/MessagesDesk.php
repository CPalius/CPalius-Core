<?php

declare(strict_types=1);

namespace Modules\Messages\Admin;

final class MessagesDesk
{
    public const TAB_OVERVIEW = 'overview';
    public const TAB_REPORTS = 'reports';
    public const TAB_QUOTA = 'quota';
    public const TAB_SETTINGS = 'settings';

    /**
     * @return list<array{id: string, label: string, icon: string, route: string, capability: string}>
     */
    public static function tabs(): array
    {
        return [
            [
                'id' => self::TAB_OVERVIEW,
                'label' => 'messages.desk.tab.overview',
                'icon' => 'heroicons:chat-bubble-left-right',
                'route' => 'admin_messages_dashboard',
                'capability' => 'messages.moderate',
            ],
            [
                'id' => self::TAB_REPORTS,
                'label' => 'messages.desk.tab.reports',
                'icon' => 'heroicons:flag',
                'route' => 'admin_messages_reports_index',
                'capability' => 'messages.report.moderate',
            ],
            [
                'id' => self::TAB_QUOTA,
                'label' => 'messages.desk.tab.quota',
                'icon' => 'heroicons:chart-bar',
                'route' => 'admin_messages_quota_index',
                'capability' => 'messages.moderate',
            ],
            [
                'id' => self::TAB_SETTINGS,
                'label' => 'messages.desk.tab.settings',
                'icon' => 'heroicons:cog-6-tooth',
                'route' => 'admin_messages_settings_index',
                'capability' => 'messages.settings.manage',
            ],
        ];
    }

    public static function tabForRoute(string $route): string
    {
        return match (true) {
            str_starts_with($route, 'admin_messages_reports_') => self::TAB_REPORTS,
            str_starts_with($route, 'admin_messages_quota_') => self::TAB_QUOTA,
            str_starts_with($route, 'admin_messages_settings_') => self::TAB_SETTINGS,
            default => self::TAB_OVERVIEW,
        };
    }
}
