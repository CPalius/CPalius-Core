<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Homepage mode and portal-block layout JSON (edited via Studio drag-and-drop).
 */
#[CpSetting(key: 'homepage.mode', label: 'studio.homepage.mode_card', type: 'select', default: 'portal', variants: [
    'portal' => 'studio.homepage.mode.portal',
], module: 'studio_homepage', group: 'homepage')]
#[CpSetting(
    key: 'homepage.portal.layout',
    label: 'studio.homepage.layout_field',
    type: 'textarea',
    default: '',
    module: 'studio_homepage',
    group: 'homepage',
)]
final class HomepageSettings
{
}
