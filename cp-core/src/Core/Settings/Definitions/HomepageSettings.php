<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Homepage mode, the portal template, the portal-block layout JSON (edited via
 * drag-and-drop) and the copy of the built-in "simple" portal template.
 *
 * homepage.portal.template defaults to "showcase" so a site that existed before
 * templates keeps serving the page it already had; fresh installs are set to
 * "simple" by the installer.
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
#[CpSetting(
    key: 'homepage.portal.template',
    label: 'studio.homepage.template.label',
    type: 'select',
    default: 'showcase',
    variants: [
        'simple' => 'studio.homepage.template.simple',
        'feed' => 'studio.homepage.template.feed',
        'showcase' => 'studio.homepage.template.showcase',
    ],
    module: 'studio_homepage',
    group: 'homepage',
)]
// Read by SiteAccessListener. open = a normal website; login_only = visitors see only
// the sign-in page (CRM/ERP behind a login); admin_only = no website, panels only.
#[CpSetting(key: 'core.site_access', label: 'studio.homepage.access.label', type: 'select', default: 'open', variants: [
    'open' => 'studio.homepage.access.open',
    'login_only' => 'studio.homepage.access.login_only',
    'admin_only' => 'studio.homepage.access.admin_only',
], module: 'studio_homepage', group: 'homepage')]
#[CpSetting(key: 'homepage.simple.title', label: 'studio.homepage.simple.title', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.subtitle', label: 'studio.homepage.simple.subtitle', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.body', label: 'studio.homepage.simple.body', type: 'textarea', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.cta_label', label: 'studio.homepage.simple.cta_label', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.cta_url', label: 'studio.homepage.simple.cta_url', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.cta2_label', label: 'studio.homepage.simple.cta2_label', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.cta2_url', label: 'studio.homepage.simple.cta2_url', type: 'text', default: '', module: 'studio_homepage', group: 'homepage', translatable: true)]
#[CpSetting(key: 'homepage.simple.image', label: 'studio.homepage.simple.image', type: 'text', default: '', module: 'studio_homepage', group: 'homepage')]
final class HomepageSettings
{
}
