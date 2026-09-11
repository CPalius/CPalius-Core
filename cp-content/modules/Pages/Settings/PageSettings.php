<?php

declare(strict_types=1);

namespace Modules\Pages\Settings;

use App\Core\Annotation\CpSetting;
use Modules\Pages\PageTemplate;

#[CpSetting(
    key: 'pages.default_template',
    label: 'pages.settings.default_template',
    type: 'select',
    default: PageTemplate::DEFAULT,
    variants: [
        PageTemplate::DEFAULT => 'pages.template.default',
        PageTemplate::FULLWIDTH => 'pages.template.fullwidth',
        PageTemplate::LANDING => 'pages.template.landing',
    ],
    module: 'pages',
    group: 'pages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'pages.meta_description_fallback',
    label: 'pages.settings.meta_description_fallback',
    type: 'textarea',
    default: '',
    module: 'pages',
    group: 'pages',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'pages.allow_custom_js',
    label: 'pages.settings.allow_custom_js',
    type: 'checkbox',
    default: true,
    module: 'pages',
    group: 'pages',
    scope: CpSetting::SCOPE_MODULE,
)]
final class PageSettings
{
}
