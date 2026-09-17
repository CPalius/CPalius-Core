<?php

declare(strict_types=1);

namespace Modules\Blog\Settings;

use App\Core\Annotation\CpSetting;
use Modules\Blog\PostSubType;

/**
 * Blog module settings. Empty by design: a pure #[CpSetting] carrier class.
 * scope: SCOPE_MODULE places them in the "Modules" tab of /aacp/settings.
 */
#[CpSetting(
    key: 'blog.default_posts_per_page',
    label: 'blog.settings.default_posts_per_page',
    type: 'integer',
    default: 10,
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.default_post_sub_type',
    label: 'blog.settings.default_post_sub_type',
    type: 'select',
    default: PostSubType::ARTICLE,
    variants: [
        PostSubType::ARTICLE => 'blog.posts.sub_type.article',
        PostSubType::PROJECT => 'blog.posts.sub_type.project',
        PostSubType::SOFTWARE => 'blog.posts.sub_type.software',
        PostSubType::NOTE => 'blog.posts.sub_type.note',
    ],
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    // "%%" escapes the DI parameter syntax: this default travels inside the
    // cpalius.setting_definitions container parameter and resolves to "%" at runtime.
    default: '%%title%% - %%site_name%%',
    key: 'blog.seo_title_pattern',
    label: 'blog.settings.seo_title_pattern',
    type: 'text',
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.meta_description_fallback',
    label: 'blog.settings.meta_description_fallback',
    type: 'textarea',
    default: '',
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.list_excerpt_length',
    label: 'blog.settings.list_excerpt_length',
    type: 'integer',
    default: 160,
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.list_excerpt_unit',
    label: 'blog.settings.list_excerpt_unit',
    type: 'select',
    default: 'chars',
    variants: [
        'chars' => 'blog.settings.excerpt_unit.chars',
        'words' => 'blog.settings.excerpt_unit.words',
    ],
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.list_layout',
    label: 'blog.settings.list_layout',
    type: 'select',
    default: 'grid',
    variants: [
        'grid' => 'blog.settings.list_layout.grid',
        'list' => 'blog.settings.list_layout.list',
    ],
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_enabled',
    label: 'blog.settings.hero_enabled',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_label',
    label: 'blog.settings.hero_label',
    type: 'text',
    default: 'CPalius Blog',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_title',
    label: 'blog.settings.hero_title',
    type: 'text',
    default: 'Mimari, ürün ve mühendislik notları',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_subtitle',
    label: 'blog.settings.hero_subtitle',
    type: 'text',
    default: 'Kurumsal CMF deneyiminden süzülen yazılar',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_description',
    label: 'blog.settings.hero_description',
    type: 'textarea',
    default: 'Modüler mimari, güvenlik anayasası, performans ve Studio içerik akışı üzerine derinlemesine makaleler, proje güncellemeleri ve mühendislik notları.',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_primary_label',
    label: 'blog.settings.hero_cta_primary_label',
    type: 'text',
    default: 'Son yazıları oku',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_primary_href',
    label: 'blog.settings.hero_cta_primary_href',
    type: 'text',
    default: '#blog-feed',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_cta_secondary_label',
    label: 'blog.settings.hero_cta_secondary_label',
    type: 'text',
    default: 'Kategorilere göz at',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_secondary_href',
    label: 'blog.settings.hero_cta_secondary_href',
    type: 'text',
    default: '#blog-categories',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_image_asset_id',
    label: 'blog.settings.hero_image_asset_id',
    type: 'text',
    default: '',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_show_stats',
    label: 'blog.settings.hero_show_stats',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.featured_enabled',
    label: 'blog.settings.featured_enabled',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.featured_limit',
    label: 'blog.settings.featured_limit',
    type: 'integer',
    default: 8,
    module: 'blog',
    group: 'blog_showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_enabled',
    label: 'blog.settings.comments_enabled',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_allow_guests',
    label: 'blog.settings.comments_allow_guests',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_guest_require_approval',
    label: 'blog.settings.comments_guest_require_approval',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_member_require_approval',
    label: 'blog.settings.comments_member_require_approval',
    type: 'checkbox',
    default: false,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_allow_replies',
    label: 'blog.settings.comments_allow_replies',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_allow_edit',
    label: 'blog.settings.comments_allow_edit',
    type: 'checkbox',
    default: false,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_require_captcha',
    label: 'blog.settings.comments_require_captcha',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_captcha_members',
    label: 'blog.settings.comments_captcha_members',
    type: 'checkbox',
    default: false,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_per_page',
    label: 'blog.settings.comments_per_page',
    type: 'integer',
    default: 20,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_max_length',
    label: 'blog.settings.comments_max_length',
    type: 'integer',
    default: 4000,
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
)]
/*
 * Related posts. Used to be three same-category posts wedged into the sidebar
 * with nothing to turn off and nothing to tune; the block now sits under the
 * comments and reads its source from here.
 */
#[CpSetting(
    key: 'blog.related_enabled',
    label: 'blog.settings.related_enabled',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_related',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.related_title',
    label: 'blog.settings.related_title',
    type: 'text',
    // Empty falls back to the shipped translation, so the heading is already
    // correct in every active language without anyone typing it twice.
    default: '',
    module: 'blog',
    group: 'blog_related',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.related_source',
    label: 'blog.settings.related_source',
    type: 'select',
    default: 'same_category',
    variants: [
        'same_category' => 'blog.settings.related_source.same_category',
        'same_tags' => 'blog.settings.related_source.same_tags',
        'fixed_category' => 'blog.settings.related_source.fixed_category',
        'latest' => 'blog.settings.related_source.latest',
    ],
    module: 'blog',
    group: 'blog_related',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    // Options are filled at runtime by BlogCategoryVariantProvider: the
    // categories a site has cannot be compiled into the container.
    key: 'blog.related_category',
    label: 'blog.settings.related_category',
    type: 'select',
    default: '',
    module: 'blog',
    group: 'blog_related',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.related_limit',
    label: 'blog.settings.related_limit',
    type: 'integer',
    default: 3,
    module: 'blog',
    group: 'blog_related',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.comments_closed_notice',
    label: 'blog.settings.comments_closed_notice',
    type: 'textarea',
    default: '',
    module: 'blog',
    group: 'blog_comments',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
final class BlogSettings
{
}
