<?php

declare(strict_types=1);

namespace Modules\Forum\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Forum settings listed under AACP Module Settings.
 */
#[CpSetting(
    key: 'forum.threads_per_page',
    label: 'forum.settings.threads_per_page',
    type: 'integer',
    default: 30,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.posts_per_page',
    label: 'forum.settings.posts_per_page',
    type: 'integer',
    default: 15,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.allow_guest_view',
    label: 'forum.settings.allow_guest_view',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.fast_reply_enabled',
    label: 'forum.settings.fast_reply_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.edit_time_limit',
    label: 'forum.settings.edit_time_limit',
    type: 'integer',
    default: 15,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.attachments_enabled',
    label: 'forum.settings.attachments_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.attachments_max_per_post',
    label: 'forum.settings.attachments_max_per_post',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.attachments_max_kb',
    label: 'forum.settings.attachments_max_kb',
    type: 'integer',
    default: 2048,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.polls_enabled',
    label: 'forum.settings.polls_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.drafts_enabled',
    label: 'forum.settings.drafts_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.watch_enabled',
    label: 'forum.settings.watch_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.watch_auto_on_reply',
    label: 'forum.settings.watch_auto_on_reply',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.post_moderation_enabled',
    label: 'forum.settings.post_moderation_enabled',
    type: 'checkbox',
    default: false,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.post_moderation_min_posts',
    label: 'forum.settings.post_moderation_min_posts',
    type: 'integer',
    default: 5,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.censor_enabled',
    label: 'forum.settings.censor_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.embeds_enabled',
    label: 'forum.settings.embeds_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.unfurl_enabled',
    label: 'forum.settings.unfurl_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
final class ForumSettings
{
}
