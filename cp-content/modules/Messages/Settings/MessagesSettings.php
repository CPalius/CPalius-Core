<?php

declare(strict_types=1);

namespace Modules\Messages\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Messages module settings. Pure #[CpSetting] carrier — values are read through
 * MessagesConfig, never from this class.
 */
#[CpSetting(
    key: 'messages.enabled',
    label: 'messages.settings.enabled',
    type: 'checkbox',
    default: true,
    module: 'messages',
    group: 'messages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.allow_new_threads',
    label: 'messages.settings.allow_new_threads',
    type: 'checkbox',
    default: true,
    module: 'messages',
    group: 'messages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.threads_per_page',
    label: 'messages.settings.threads_per_page',
    type: 'integer',
    default: 20,
    module: 'messages',
    group: 'messages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.messages_per_page',
    label: 'messages.settings.messages_per_page',
    type: 'integer',
    default: 30,
    module: 'messages',
    group: 'messages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.max_body_length',
    label: 'messages.settings.max_body_length',
    type: 'integer',
    default: 5000,
    module: 'messages',
    group: 'messages',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.min_account_age_hours',
    label: 'messages.settings.min_account_age_hours',
    type: 'integer',
    default: 0,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.hourly_send_limit',
    label: 'messages.settings.hourly_send_limit',
    type: 'integer',
    default: 20,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.daily_send_limit',
    label: 'messages.settings.daily_send_limit',
    type: 'integer',
    default: 50,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.daily_new_thread_limit',
    label: 'messages.settings.daily_new_thread_limit',
    type: 'integer',
    default: 10,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.flood_window',
    label: 'messages.settings.flood_window',
    type: 'integer',
    default: 60,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.flood_limit',
    label: 'messages.settings.flood_limit',
    type: 'integer',
    default: 8,
    module: 'messages',
    group: 'messages_limits',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.default_allow_from',
    label: 'messages.settings.default_allow_from',
    type: 'select',
    default: 'everyone',
    variants: [
        'everyone' => 'messages.privacy.everyone',
        'contacts' => 'messages.privacy.contacts',
        'nobody' => 'messages.privacy.nobody',
    ],
    module: 'messages',
    group: 'messages_privacy',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.notifications_enabled',
    label: 'messages.settings.notifications_enabled',
    type: 'checkbox',
    default: true,
    module: 'messages',
    group: 'messages_privacy',
    scope: CpSetting::SCOPE_MODULE,
)]
#[CpSetting(
    key: 'messages.hero_title',
    label: 'messages.settings.hero_title',
    type: 'text',
    default: '',
    module: 'messages',
    group: 'messages_appearance',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
#[CpSetting(
    key: 'messages.hero_description',
    label: 'messages.settings.hero_description',
    type: 'textarea',
    default: '',
    module: 'messages',
    group: 'messages_appearance',
    scope: CpSetting::SCOPE_MODULE,
    translatable: true,
)]
final class MessagesSettings
{
}
