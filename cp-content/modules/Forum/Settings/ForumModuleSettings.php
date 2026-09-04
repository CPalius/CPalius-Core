<?php

declare(strict_types=1);

namespace Modules\Forum\Settings;

use App\Core\Annotation\CpSetting;

#[CpSetting(
    key: 'forum.hot_topic_threshold',
    label: 'Popüler Konu Eşiği (mesaj sayısı)',
    type: 'integer',
    default: 20,
    module: 'forum',
    group: 'forum',
)]
#[CpSetting(
    key: 'forum.hide_private_topics',
    label: 'Özel Konuları Listelerden Gizle',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum',
)]
#[CpSetting(
    key: 'forum.home_label',
    label: 'Ana Sayfa Etiketi (ör. Topluluk)',
    type: 'text',
    default: 'Topluluk',
    module: 'forum',
    group: 'forum.home',
)]
#[CpSetting(
    key: 'forum.home_title',
    label: 'Ana Sayfa Başlığı',
    type: 'text',
    default: 'Forum',
    module: 'forum',
    group: 'forum.home',
)]
#[CpSetting(
    key: 'forum.home_description',
    label: 'Ana Sayfa Açıklaması',
    type: 'textarea',
    default: 'Sorularınızı sorun, deneyimlerinizi paylaşın, tartışmalara katılın.',
    module: 'forum',
    group: 'forum.home',
)]
#[CpSetting(
    key: 'forum.home_meta_description',
    label: 'Ana Sayfa SEO Açıklaması',
    type: 'textarea',
    default: 'Topluluk forumu: sorular, tartışmalar ve duyurular.',
    module: 'forum',
    group: 'forum.home',
)]
#[CpSetting(
    key: 'forum.activity_enabled',
    label: 'Son Olaylar Panosunu Göster',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_latest_topics',
    label: 'Son Olaylar: Son Açılan Konular',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_latest_posts',
    label: 'Son Olaylar: Son Cevaplanan Konular',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_newest_users',
    label: 'Son Olaylar: Yeni Üyeler',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_top_posters',
    label: 'Son Olaylar: En Çok Mesaj Yazanlar',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_per_tab',
    label: 'Son Olaylar: İlk Yükleme Adedi',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_load_more',
    label: 'Son Olaylar: Daha Fazla Adedi',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.reputation_enabled',
    label: 'Reputation Sistemini Aç',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.reputation',
)]
#[CpSetting(
    key: 'forum.notifications_enabled',
    label: 'Forum Bildirimlerini Aç',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.notifications',
)]
final class ForumModuleSettings
{
}
