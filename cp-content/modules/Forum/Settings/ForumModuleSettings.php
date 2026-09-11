<?php

declare(strict_types=1);

namespace Modules\Forum\Settings;

use App\Core\Annotation\CpSetting;

#[CpSetting(
    key: 'forum.hot_topic_threshold',
    label: 'forum.settings.hot_topic_threshold',
    type: 'integer',
    default: 20,
    module: 'forum',
    group: 'forum',
)]
#[CpSetting(
    key: 'forum.hide_private_topics',
    label: 'forum.settings.hide_private_topics',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum',
)]
#[CpSetting(
    key: 'forum.home_label',
    label: 'forum.settings.home_label',
    type: 'text',
    default: 'Topluluk',
    module: 'forum',
    group: 'forum.home',
    translatable: true,
)]
#[CpSetting(
    key: 'forum.home_title',
    label: 'forum.settings.home_title',
    type: 'text',
    default: 'Forum',
    module: 'forum',
    group: 'forum.home',
    translatable: true,
)]
#[CpSetting(
    key: 'forum.home_description',
    label: 'forum.settings.home_description',
    type: 'textarea',
    default: 'Sorularınızı sorun, deneyimlerinizi paylaşın, tartışmalara katılın.',
    module: 'forum',
    group: 'forum.home',
    translatable: true,
)]
#[CpSetting(
    key: 'forum.home_meta_description',
    label: 'forum.settings.home_meta_description',
    type: 'textarea',
    default: 'Topluluk forumu: sorular, tartışmalar ve duyurular.',
    module: 'forum',
    group: 'forum.home',
    translatable: true,
)]
#[CpSetting(
    key: 'forum.home_meta_keywords',
    label: 'forum.settings.home_meta_keywords',
    type: 'text',
    default: '',
    module: 'forum',
    group: 'forum.home',
    translatable: true,
)]
#[CpSetting(
    key: 'forum.activity_enabled',
    label: 'forum.settings.activity_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_latest_topics',
    label: 'forum.settings.activity_show_latest_topics',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_latest_posts',
    label: 'forum.settings.activity_show_latest_posts',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_newest_users',
    label: 'forum.settings.activity_show_newest_users',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_show_top_posters',
    label: 'forum.settings.activity_show_top_posters',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_per_tab',
    label: 'forum.settings.activity_per_tab',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.activity_load_more',
    label: 'forum.settings.activity_load_more',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.activity',
)]
#[CpSetting(
    key: 'forum.reputation_enabled',
    label: 'forum.settings.reputation_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.reputation',
)]
#[CpSetting(
    key: 'forum.notifications_enabled',
    label: 'forum.settings.notifications_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.notifications',
)]
final class ForumModuleSettings
{
}
