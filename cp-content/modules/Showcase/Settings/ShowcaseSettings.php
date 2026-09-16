<?php

declare(strict_types=1);

namespace Modules\Showcase\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Showcase module settings. A pure #[CpSetting] carrier — the class body stays
 * empty by design (same shape as Blog's carrier); values are read through
 * ShowcaseConfig, never from here.
 */
#[CpSetting(
    key: 'showcase.items_per_page',
    label: 'showcase.settings.items_per_page',
    type: 'integer',
    default: 12,
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.require_approval',
    label: 'showcase.settings.require_approval',
    type: 'checkbox',
    default: true,
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.max_items_per_member',
    label: 'showcase.settings.max_items_per_member',
    type: 'integer',
    default: 20,
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.max_gallery_images',
    label: 'showcase.settings.max_gallery_images',
    type: 'integer',
    default: 8,
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.max_image_mb',
    label: 'showcase.settings.max_image_mb',
    type: 'integer',
    default: 4,
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.default_currency',
    label: 'showcase.settings.default_currency',
    type: 'text',
    default: 'TRY',
    module: 'showcase',
    group: 'showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.submit_rate_limit',
    label: 'showcase.settings.submit_rate_limit',
    type: 'integer',
    default: 10,
    module: 'showcase',
    group: 'showcase_security',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.reviews_enabled',
    label: 'showcase.settings.reviews_enabled',
    type: 'checkbox',
    default: true,
    module: 'showcase',
    group: 'showcase_reviews',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.reviews_require_approval',
    label: 'showcase.settings.reviews_require_approval',
    type: 'checkbox',
    default: true,
    module: 'showcase',
    group: 'showcase_reviews',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.reviews_allow_owner',
    label: 'showcase.settings.reviews_allow_owner',
    type: 'checkbox',
    default: false,
    module: 'showcase',
    group: 'showcase_reviews',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.listing_layout',
    label: 'showcase.settings.listing_layout',
    type: 'select',
    default: 'grid',
    variants: [
        'grid' => 'showcase.settings.listing_layout.grid',
        'list' => 'showcase.settings.listing_layout.list',
    ],
    module: 'showcase',
    group: 'showcase_appearance',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'showcase.hero_title',
    label: 'showcase.settings.hero_title',
    type: 'text',
    default: '',
    module: 'showcase',
    group: 'showcase_appearance',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'showcase.hero_description',
    label: 'showcase.settings.hero_description',
    type: 'textarea',
    default: '',
    module: 'showcase',
    group: 'showcase_appearance',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'showcase.seo_title_pattern',
    label: 'showcase.settings.seo_title_pattern',
    type: 'text',
    // "%%" escapes the DI parameter syntax: this default travels inside the
    // cpalius.setting_definitions container parameter and resolves to "%".
    default: '%%title%% - %%site_name%%',
    module: 'showcase',
    group: 'showcase_appearance',
    scope: CpSetting::SCOPE_MODULE,
)]
final class ShowcaseSettings
{
}
