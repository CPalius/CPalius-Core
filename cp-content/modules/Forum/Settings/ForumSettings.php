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
    key: 'forum.guest_hide_postbit',
    label: 'forum.settings.guest_hide_postbit',
    type: 'checkbox',
    default: false,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.guest_hide_content',
    label: 'forum.settings.guest_hide_content',
    type: 'checkbox',
    default: false,
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
    key: 'forum.edit_time_limit_topic',
    label: 'forum.settings.edit_time_limit_topic',
    type: 'integer',
    default: 120,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.antibump_enabled',
    label: 'forum.settings.antibump_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.antibump',
)]
#[CpSetting(
    key: 'forum.antibump_scope',
    label: 'forum.settings.antibump_scope',
    type: 'select',
    default: 'topic_author',
    variants: [
        'topic_author' => 'forum.settings.antibump_scope_topic_author',
        'everyone' => 'forum.settings.antibump_scope_everyone',
    ],
    module: 'forum',
    group: 'forum.antibump',
)]
#[CpSetting(
    key: 'forum.antibump_window_minutes',
    label: 'forum.settings.antibump_window_minutes',
    type: 'integer',
    default: 0,
    module: 'forum',
    group: 'forum.antibump',
)]
#[CpSetting(
    key: 'forum.signatures_enabled',
    label: 'forum.settings.signatures_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.member',
)]
#[CpSetting(
    key: 'forum.signature_max_length',
    label: 'forum.settings.signature_max_length',
    type: 'integer',
    default: 300,
    module: 'forum',
    group: 'forum.member',
)]
#[CpSetting(
    key: 'forum.word_filter_enabled',
    label: 'forum.settings.word_filter_enabled',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.member',
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
    key: 'forum.warning_auto_threshold',
    label: 'forum.settings.warning_auto_threshold',
    type: 'integer',
    default: 10,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.warning_auto_action',
    label: 'forum.settings.warning_auto_action',
    type: 'select',
    default: 'mute',
    variants: [
        'mute' => 'forum.settings.warning_auto_action_mute',
        'ban' => 'forum.settings.warning_auto_action_ban',
    ],
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
/*
 * Postbit layout. Declared here so the values live in cp_settings like every
 * other forum preference, but edited on their own screen: an ordered list and a
 * stylesheet are not things a generic key/value form can express.
 *
 * module: 'forum_postbit' is a pseudo-module in SettingDefinition::HIDDEN_MODULES,
 * the project's existing way of saying "this key has its own screen". Without it
 * the generic tabs would render the raw comma-separated order as a text box.
 */
#[CpSetting(
    key: 'forum.postbit_order',
    label: 'forum.settings.postbit_order',
    type: 'text',
    default: '',
    module: 'forum_postbit',
    group: 'forum.postbit',
)]
#[CpSetting(
    key: 'forum.postbit_hidden',
    label: 'forum.settings.postbit_hidden',
    type: 'text',
    default: '',
    module: 'forum_postbit',
    group: 'forum.postbit',
)]
#[CpSetting(
    key: 'forum.postbit_css',
    label: 'forum.settings.postbit_css',
    type: 'textarea',
    default: '',
    module: 'forum_postbit',
    group: 'forum.postbit',
)]
final class ForumSettings
{
}
