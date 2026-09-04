<?php

declare(strict_types=1);

namespace Modules\Roadmap\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Roadmap source bindings and list limits. Attribute-only carrier (BlogModuleSettings pattern).
 */
#[CpSetting(
    key: 'roadmap.native_enabled',
    label: 'Native roadmap kayıtlarını göster',
    type: 'checkbox',
    default: '1',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.blog_enabled',
    label: 'Blog kategorisini yan panele dahil et',
    type: 'checkbox',
    default: '0',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.blog_category_id',
    label: 'Blog kategori ID',
    type: 'integer',
    default: 0,
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_enabled',
    label: 'Forum bölümünü yan panele dahil et',
    type: 'checkbox',
    default: '0',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_section_id',
    label: 'Forum alt kategori (section) ID',
    type: 'integer',
    default: 0,
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.forum_user_ids',
    label: 'Forum yazar ID listesi (virgülle; boş = tümü)',
    type: 'text',
    default: '',
    module: 'roadmap',
    group: 'roadmap_sources',
)]
#[CpSetting(
    key: 'roadmap.native_limit',
    label: 'Sayfada gösterilecek Studio (native) kayıt sayısı',
    type: 'integer',
    default: 8,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.blog_limit',
    label: 'Yan panel Blog içerik sayısı',
    type: 'integer',
    default: 5,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.forum_limit',
    label: 'Yan panel Forum içerik sayısı',
    type: 'integer',
    default: 5,
    module: 'roadmap',
    group: 'roadmap_display',
)]
#[CpSetting(
    key: 'roadmap.portal_native_limit',
    label: 'Ana sayfa widget — Studio kayıt sayısı',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
#[CpSetting(
    key: 'roadmap.portal_blog_limit',
    label: 'Ana sayfa widget — Blog kayıt sayısı',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
#[CpSetting(
    key: 'roadmap.portal_forum_limit',
    label: 'Ana sayfa widget — Forum kayıt sayısı',
    type: 'integer',
    default: 3,
    module: 'roadmap',
    group: 'roadmap_portal',
)]
final class RoadmapModuleSettings
{
}
