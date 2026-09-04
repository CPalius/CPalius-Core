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
    label: 'Sayfa Başına Gösterilecek Yazı Sayısı',
    type: 'integer',
    default: 10,
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.default_post_sub_type',
    label: 'Yeni Yazı Eklenirken Varsayılan Tür',
    type: 'select',
    default: PostSubType::ARTICLE,
    variants: [
        PostSubType::ARTICLE => 'Makale',
        PostSubType::PROJECT => 'Proje',
        PostSubType::SOFTWARE => 'Yazılım / Ürün',
        PostSubType::NOTE => 'Not',
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
    label: 'Blog SEO Başlık Şablonu',
    type: 'text',
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.meta_description_fallback',
    label: 'Varsayılan Meta Açıklaması (Yazıda Tanımlı Değilse)',
    type: 'textarea',
    default: '',
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.list_excerpt_length',
    label: 'Liste Özet Uzunluğu',
    type: 'integer',
    default: 160,
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.list_excerpt_unit',
    label: 'Liste Özet Birimi',
    type: 'select',
    default: 'chars',
    variants: [
        'chars' => 'Karakter',
        'words' => 'Kelime',
    ],
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.list_layout',
    label: 'Blog Liste Görünümü',
    type: 'select',
    default: 'grid',
    variants: [
        'grid' => 'Izgara (Kartlar)',
        'list' => 'Liste',
    ],
    module: 'blog',
    group: 'blog',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_enabled',
    label: 'Hero Alanını Göster',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_label',
    label: 'Hero Üst Etiket',
    type: 'text',
    default: 'CPalius Blog',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_title',
    label: 'Hero Başlık',
    type: 'text',
    default: 'Mimari, ürün ve mühendislik notları',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_subtitle',
    label: 'Hero Alt Başlık',
    type: 'text',
    default: 'Kurumsal CMF deneyiminden süzülen yazılar',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_description',
    label: 'Hero Açıklama',
    type: 'textarea',
    default: 'Modüler mimari, güvenlik anayasası, performans ve Studio içerik akışı üzerine derinlemesine makaleler, proje güncellemeleri ve mühendislik notları.',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_primary_label',
    label: 'Birincil Buton Metni',
    type: 'text',
    default: 'Son yazıları oku',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_primary_href',
    label: 'Birincil Buton Linki',
    type: 'text',
    default: '#blog-feed',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_cta_secondary_label',
    label: 'İkincil Buton Metni',
    type: 'text',
    default: 'Kategorilere göz at',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'blog.hero_cta_secondary_href',
    label: 'İkincil Buton Linki',
    type: 'text',
    default: '#blog-categories',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_image_asset_id',
    label: 'Hero Görseli (Medya Asset ID)',
    type: 'text',
    default: '',
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.hero_show_stats',
    label: 'Hero İstatistiklerini Göster',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_hero',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.featured_enabled',
    label: 'Öne Çıkan Yazı Şeridini Göster',
    type: 'checkbox',
    default: true,
    module: 'blog',
    group: 'blog_showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'blog.featured_limit',
    label: 'Öne Çıkan Yazı Adedi',
    type: 'integer',
    default: 8,
    module: 'blog',
    group: 'blog_showcase',
    scope: CpSetting::SCOPE_MODULE,
)]
final class BlogSettings
{
}
