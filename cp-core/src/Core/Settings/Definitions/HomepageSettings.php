<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Sitenin ana sayfasını (kök "/" route'u) ve portal blok layout'unu
 * belirleyen ayarlar. Layout JSON'u Studio > Ana Sayfa ekranından
 * sürükle-bırak ile yönetilir (bkz. PortalLayoutService).
 */
#[CpSetting(key: 'homepage.mode', label: 'Ana Sayfa Modu', type: 'select', default: 'portal', variants: [
    'portal' => 'Portal (blok vitrin)',
    'forum' => 'Forum Ana Sayfası',
    'blog' => 'Blog Ana Sayfası',
], module: 'studio_homepage', group: 'homepage')]
#[CpSetting(
    key: 'homepage.portal.layout',
    label: 'Portal Blok Düzeni',
    type: 'textarea',
    default: '',
    module: 'studio_homepage',
    group: 'homepage',
)]
final class HomepageSettings
{
}
