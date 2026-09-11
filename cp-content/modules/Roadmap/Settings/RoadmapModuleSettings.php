<?php

declare(strict_types=1);

namespace Modules\Roadmap\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Roadmap source bindings and list limits. Attribute-only carrier (BlogModuleSettings pattern).
 */
#[CpSetting(
    key: 'roadmap.native_enabled',
    label: 'roadmap.settings.native_enabled',
    type: 'checkbox',
    default: '1',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.blog_enabled',
    label: 'roadmap.settings.blog_enabled',
    type: 'checkbox',
    default: '0',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.blog_category_id',
    label: 'roadmap.settings.blog_category_id',
    type: 'integer',
    default: 0,
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_enabled',
    label: 'roadmap.settings.forum_enabled',
    type: 'checkbox',
    default: '0',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_section_id',
    label: 'roadmap.settings.forum_section_id',
    type: 'integer',
    default: 0,
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_user_ids',
    label: 'roadmap.settings.forum_user_ids',
    type: 'text',
    default: '',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.native_limit',
    label: 'roadmap.settings.native_limit',
    type: 'integer',
    default: 8,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.blog_limit',
    label: 'roadmap.settings.blog_limit',
    type: 'integer',
    default: 5,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.forum_limit',
    label: 'roadmap.settings.forum_limit',
    type: 'integer',
    default: 5,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.portal_native_limit',
    label: 'roadmap.settings.portal_native_limit',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
#[CpSetting(
    key: 'roadmap.portal_blog_limit',
    label: 'roadmap.settings.portal_blog_limit',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
#[CpSetting(
    key: 'roadmap.portal_forum_limit',
    label: 'roadmap.settings.portal_forum_limit',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
final class RoadmapModuleSettings
{
}
