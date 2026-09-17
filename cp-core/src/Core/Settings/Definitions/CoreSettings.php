<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Core settings carrier: empty class whose #[CpSetting] attributes are read at compile time.
 */
/*
 * Visitor-facing strings are translatable JSON maps; behaviour keys (maintenance, timezone, permalink) are not.
 */
#[CpSetting(key: 'core.site_name', label: 'settings.core.site_name', type: 'text', default: 'CPalius CMF', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.site_description', label: 'settings.core.site_description', type: 'textarea', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.hero_title', label: 'settings.core.hero_title', type: 'text', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.maintenance_mode', label: 'settings.core.maintenance_mode', type: 'checkbox', default: false, group: 'genel')]
/*
 * Read by MaintenanceModeListener. Empty on purpose: a blank field falls back to
 * the shipped translation, so a fresh installation is already multilingual and
 * only the operator who wants their own wording has to type anything.
 */
#[CpSetting(key: 'core.maintenance_title', label: 'settings.core.maintenance_title', type: 'text', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.maintenance_message', label: 'settings.core.maintenance_message', type: 'textarea', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.default_locale', label: 'settings.core.default_locale', type: 'select', default: 'tr', group: 'genel')]
#[CpSetting(key: 'core.timezone', label: 'settings.core.timezone', type: 'select', default: 'Europe/Istanbul', variants: [
    'Europe/Istanbul' => 'settings.timezone.europe_istanbul',
    'Europe/London' => 'settings.timezone.europe_london',
    'Europe/Berlin' => 'settings.timezone.europe_berlin',
    'America/New_York' => 'settings.timezone.america_new_york',
    'America/Los_Angeles' => 'settings.timezone.america_los_angeles',
    'Asia/Dubai' => 'settings.timezone.asia_dubai',
    'Asia/Tokyo' => 'settings.timezone.asia_tokyo',
    'Australia/Sydney' => 'settings.timezone.australia_sydney',
    'UTC' => 'UTC',
], group: 'genel')]
/**
 * Preference only today: routing still uses Node::slug + locale, not this structure.
 */
#[CpSetting(key: 'core.permalink_structure', label: 'settings.core.permalink_structure', type: 'select', default: 'postname', variants: [
    // Escape '%' as '%%' so the DI parameter bag does not treat it as %param%.
    'postname' => '/%%postname%%/',
    'year_month_postname' => '/%%year%%/%%month%%/%%postname%%/',
], group: 'genel')]
#[CpSetting(key: 'core.default_meta_title_format', label: 'settings.core.default_meta_title_format', type: 'text', default: '%%title%% – %%site_name%%', group: 'genel')]
#[CpSetting(key: 'core.default_meta_description', label: 'settings.core.default_meta_description', type: 'textarea', default: '', group: 'genel', translatable: true)]
/*
 * Active frontend theme directory name. Owned by "theme_manager" so it stays out of
 * the generic settings tabs: /aacp/themes is its only safe editor (a typo breaks rendering).
 */
#[CpSetting(
    key: 'core.active_theme',
    label: 'settings.core.active_theme',
    type: 'text',
    default: 'cpalius-website',
    module: 'theme_manager',
    group: 'appearance',
)]
final class CoreSettings
{
}
