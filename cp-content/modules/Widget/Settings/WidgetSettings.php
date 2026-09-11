<?php

declare(strict_types=1);

namespace Modules\Widget\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Widget module settings. Empty by design: a pure #[CpSetting] carrier class.
 * scope: SCOPE_MODULE places them in the "Modules" tab of /aacp/settings.
 */
#[CpSetting(
    key: 'widget.sidebar_enabled',
    label: 'widget.settings.sidebar_enabled',
    type: 'checkbox',
    default: true,
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.sidebar_position',
    label: 'widget.settings.sidebar_position',
    type: 'select',
    default: 'right',
    variants: [
        'left' => 'widget.settings.position.left',
        'right' => 'widget.settings.position.right',
    ],
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.sidebar_title',
    label: 'widget.settings.sidebar_title',
    type: 'text',
    default: '',
    module: 'widget',
    group: 'widget',
    translatable: true,
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.max_items_per_widget',
    label: 'widget.settings.max_items_per_widget',
    type: 'integer',
    default: 5,
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.footer_enabled',
    label: 'widget.settings.footer_enabled',
    type: 'checkbox',
    default: true,
    module: 'widget',
    group: 'widget_footer',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.footer_columns',
    label: 'widget.settings.footer_columns',
    type: 'select',
    default: '3',
    variants: [
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
    ],
    module: 'widget',
    group: 'widget_footer',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.footer_note',
    label: 'widget.settings.footer_note',
    type: 'textarea',
    default: '',
    module: 'widget',
    group: 'widget_footer',
    translatable: true,
    scope: CpSetting::SCOPE_MODULE,
)]
final class WidgetSettings
{
}
