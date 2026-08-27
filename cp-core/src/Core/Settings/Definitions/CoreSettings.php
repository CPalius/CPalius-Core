<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Çekirdeğin kendi ayarları. Bu sınıf kasıtlı olarak boştur — sadece
 * derleme zamanında SettingsRegistrationPass tarafından okunacak
 * #[CpSetting] attribute'larının taşıyıcısıdır (bkz. CpSetting docblock'u).
 */
#[CpSetting(key: 'core.site_name', label: 'Site Adı', type: 'text', default: 'CPalius CMF', group: 'genel')]
#[CpSetting(key: 'core.maintenance_mode', label: 'Bakım Modu', type: 'checkbox', default: false, group: 'genel')]
#[CpSetting(key: 'core.default_locale', label: 'Varsayılan Dil', type: 'select', default: 'tr', variants: ['tr' => 'Türkçe', 'en' => 'English'], group: 'genel')]
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
 * NOT: Bu ayar şu an yalnızca gösterge/tercih amaçlıdır — CPalius'un
 * mevcut yönlendirme (routing) katmanı Node::slug + Node::locale
 * kombinasyonuna dayanır (bkz. SafeModuleRouteLoader, LocaleListener).
 * Seçilen permalink yapısının fiilen URL üretimine/route eşleşmesine
 * entegre edilmesi kapsam dışıdır, ileride ayrı bir iterasyon gerektirir.
 */
#[CpSetting(key: 'core.permalink_structure', label: 'Bağlantı (Permalink) Yapısı', type: 'select', default: 'postname', variants: [
    // '%' işaretleri Symfony DI container'ının parametre çözümleyicisi
    // tarafından "%param%" olarak yorumlanmasın diye '%%' ile escape
    // edilir (bkz. SettingsRegistrationPass, bu tanımları container
    // parametresi olarak enjekte eder).
    'postname' => '/%%postname%%/',
    'year_month_postname' => '/%%year%%/%%month%%/%%postname%%/',
], group: 'genel')]
#[CpSetting(key: 'core.default_meta_title_format', label: 'Varsayılan Meta Başlık Formatı', type: 'text', default: '%%title%% – %%site_name%%', group: 'genel')]
#[CpSetting(key: 'core.default_meta_description', label: 'Varsayılan Meta Açıklama', type: 'textarea', default: '', group: 'genel')]
final class CoreSettings
{
}
