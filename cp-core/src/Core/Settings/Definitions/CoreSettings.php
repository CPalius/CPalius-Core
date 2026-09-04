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
#[CpSetting(key: 'core.site_name', label: 'Site Adı', type: 'text', default: 'CPalius CMF', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.site_description', label: 'Site Açıklaması', type: 'textarea', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.hero_title', label: 'Ana Sayfa Hero Başlığı', type: 'text', default: '', group: 'genel', translatable: true)]
#[CpSetting(key: 'core.maintenance_mode', label: 'Bakım Modu', type: 'checkbox', default: false, group: 'genel')]
#[CpSetting(key: 'core.default_locale', label: 'Varsayılan Dil', type: 'select', default: 'tr', group: 'genel')]
#[CpSetting(key: 'core.timezone', label: 'Sistem Zaman Dilimi', type: 'select', default: 'Europe/Istanbul', variants: [
    'Europe/Istanbul' => 'İstanbul (UTC+3)',
    'Europe/London' => 'Londra (UTC+0/+1)',
    'Europe/Berlin' => 'Berlin (UTC+1/+2)',
    'America/New_York' => 'New York (UTC-5/-4)',
    'America/Los_Angeles' => 'Los Angeles (UTC-8/-7)',
    'Asia/Dubai' => 'Dubai (UTC+4)',
    'Asia/Tokyo' => 'Tokyo (UTC+9)',
    'Australia/Sydney' => 'Sidney (UTC+10/+11)',
    'UTC' => 'UTC',
], group: 'genel')]
/**
 * Preference only today: routing still uses Node::slug + locale, not this structure.
 */
#[CpSetting(key: 'core.permalink_structure', label: 'Bağlantı (Permalink) Yapısı', type: 'select', default: 'postname', variants: [
    // Escape '%' as '%%' so the DI parameter bag does not treat it as %param%.
    'postname' => '/%%postname%%/',
    'year_month_postname' => '/%%year%%/%%month%%/%%postname%%/',
], group: 'genel')]
#[CpSetting(key: 'core.default_meta_title_format', label: 'Varsayılan Meta Başlık Formatı', type: 'text', default: '%%title%% – %%site_name%%', group: 'genel')]
#[CpSetting(key: 'core.default_meta_description', label: 'Varsayılan Meta Açıklama', type: 'textarea', default: '', group: 'genel', translatable: true)]
/*
 * Active frontend theme directory name. Owned by "theme_manager" so it stays out of
 * the generic settings tabs: /aacp/themes is its only safe editor (a typo breaks rendering).
 */
#[CpSetting(
    key: 'core.active_theme',
    label: 'Aktif Tema',
    type: 'text',
    default: 'cpalius-website',
    module: 'theme_manager',
    group: 'appearance',
)]
final class CoreSettings
{
}
