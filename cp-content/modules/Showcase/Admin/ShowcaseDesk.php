<?php

declare(strict_types=1);

namespace Modules\Showcase\Admin;

/**
 * Tab map for the Showcase admin desk.
 *
 * The module owns five screens that belong together; without a shared nav they
 * read as five unrelated pages reached from the sidebar. Same shape as
 * ForumDesk — one place that knows which tab a route belongs to, so adding a
 * screen does not mean editing every template.
 */
final class ShowcaseDesk
{
    public const TAB_OVERVIEW = 'overview';
    public const TAB_ITEMS = 'items';
    public const TAB_TYPES = 'types';
    public const TAB_REVIEWS = 'reviews';
    public const TAB_SETTINGS = 'settings';

    /**
     * @return list<array{id: string, label: string, icon: string, route: string, capability: string}>
     */
    public static function tabs(): array
    {
        return [
            [
                'id' => self::TAB_OVERVIEW,
                'label' => 'showcase.desk.tab.overview',
                'icon' => 'heroicons:squares-2x2',
                'route' => 'admin_showcase_dashboard',
                'capability' => 'showcase.item.view.any',
            ],
            [
                'id' => self::TAB_ITEMS,
                'label' => 'showcase.desk.tab.items',
                'icon' => 'heroicons:rectangle-group',
                'route' => 'admin_showcase_items_index',
                'capability' => 'showcase.item.view.any',
            ],
            [
                'id' => self::TAB_TYPES,
                'label' => 'showcase.desk.tab.types',
                'icon' => 'heroicons:adjustments-horizontal',
                'route' => 'admin_showcase_types_index',
                'capability' => 'showcase.type.manage',
            ],
            [
                'id' => self::TAB_REVIEWS,
                'label' => 'showcase.desk.tab.reviews',
                'icon' => 'heroicons:star',
                'route' => 'admin_showcase_reviews_index',
                'capability' => 'showcase.review.moderate',
            ],
            [
                'id' => self::TAB_SETTINGS,
                'label' => 'showcase.desk.tab.settings',
                'icon' => 'heroicons:cog-6-tooth',
                'route' => 'admin_showcase_settings_index',
                'capability' => 'showcase.type.manage',
            ],
        ];
    }

    public static function tabForRoute(string $route): string
    {
        return match (true) {
            str_starts_with($route, 'admin_showcase_items_') => self::TAB_ITEMS,
            // The field designer lives under a type, so it highlights "Types".
            str_starts_with($route, 'admin_showcase_types_'),
            str_starts_with($route, 'admin_showcase_fields_') => self::TAB_TYPES,
            str_starts_with($route, 'admin_showcase_reviews_') => self::TAB_REVIEWS,
            str_starts_with($route, 'admin_showcase_settings_') => self::TAB_SETTINGS,
            default => self::TAB_OVERVIEW,
        };
    }
}
