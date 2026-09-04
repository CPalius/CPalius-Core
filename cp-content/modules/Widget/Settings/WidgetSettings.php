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
    label: 'Kenar Çubuğunu Göster',
    type: 'checkbox',
    default: true,
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.sidebar_position',
    label: 'Kenar Çubuğu Konumu',
    type: 'select',
    default: 'right',
    variants: [
        'left' => 'Sol',
        'right' => 'Sağ',
    ],
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.sidebar_title',
    label: 'Kenar Çubuğu Başlığı',
    type: 'text',
    default: '',
    module: 'widget',
    group: 'widget',
    translatable: true,
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.max_items_per_widget',
    label: 'Widget Başına Maksimum Öğe',
    type: 'integer',
    default: 5,
    module: 'widget',
    group: 'widget',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.footer_enabled',
    label: 'Alt Bilgi Widget Alanını Göster',
    type: 'checkbox',
    default: true,
    module: 'widget',
    group: 'widget_footer',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'widget.footer_columns',
    label: 'Alt Bilgi Sütun Sayısı',
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
    label: 'Alt Bilgi Notu',
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
