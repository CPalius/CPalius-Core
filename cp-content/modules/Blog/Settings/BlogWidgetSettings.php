<?php

declare(strict_types=1);

namespace Modules\Blog\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Modules\Blog\Plugin\BlogWidgetPlugin eklentisinin çalışma zamanı ayarları.
 * CoreSettings/BlogModuleSettings ile aynı desen: boş, salt attribute-taşıyıcı
 * final sınıf.
 *
 * "module" alanı bilinçli olarak "blog" DEĞİL, doğrudan
 * BlogWidgetPlugin::getName()'in döndürdüğü "blog_widget" değeridir —
 * AACP'nin isSettingOwnedByPlugin() kontrolü PluginRegistry'de kayıtlı bu
 * isimle eşleşip eşleşmediğine bakarak bu ayarları "Eklenti Ayarları"
 * ekranına yönlendirir (bkz. AACPController::settingsPlugins()).
 */
#[CpSetting(
    key: 'blog_widget.recent_posts_limit',
    label: 'Sidebar\'da Gösterilecek Son Yazı Sayısı',
    type: 'integer',
    default: 5,
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.popular_tags_limit',
    label: 'Sidebar\'da Gösterilecek Popüler Etiket Sayısı',
    type: 'integer',
    default: 10,
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.social_github_url',
    label: 'Yazar Kartı Github Adresi',
    type: 'text',
    default: '',
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.social_twitter_url',
    label: 'Yazar Kartı Twitter Adresi',
    type: 'text',
    default: '',
    module: 'blog_widget',
    group: 'blog_widget',
)]
final class BlogWidgetSettings
{
}
