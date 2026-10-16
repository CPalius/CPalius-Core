<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Cookie consent banner. Off by default: a site that sets no cookies beyond the necessary ones
 * (sessions, CSRF, language) does not need one. Texts are optional; an empty one falls back to
 * the built-in translation of the visitor's language.
 */
#[CpSetting(
    key: 'consent.enabled',
    label: 'aacp.system_settings.consent.enabled',
    type: 'checkbox',
    default: '0',
    group: 'consent',
)]
#[CpSetting(
    key: 'consent.mode_enabled',
    label: 'aacp.system_settings.consent.mode_enabled',
    type: 'checkbox',
    default: '1',
    group: 'consent',
)]
#[CpSetting(
    key: 'consent.analytics_enabled',
    label: 'aacp.system_settings.consent.analytics_enabled',
    type: 'checkbox',
    default: '1',
    group: 'consent',
)]
#[CpSetting(
    key: 'consent.marketing_enabled',
    label: 'aacp.system_settings.consent.marketing_enabled',
    type: 'checkbox',
    default: '1',
    group: 'consent',
)]
#[CpSetting(
    key: 'consent.message',
    label: 'aacp.system_settings.consent.message',
    type: 'textarea',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.accept_label',
    label: 'aacp.system_settings.consent.accept_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.reject_label',
    label: 'aacp.system_settings.consent.reject_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.preferences_label',
    label: 'aacp.system_settings.consent.preferences_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.save_label',
    label: 'aacp.system_settings.consent.save_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.necessary_label',
    label: 'aacp.system_settings.consent.necessary_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.necessary_desc',
    label: 'aacp.system_settings.consent.necessary_desc',
    type: 'textarea',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.analytics_label',
    label: 'aacp.system_settings.consent.analytics_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.analytics_desc',
    label: 'aacp.system_settings.consent.analytics_desc',
    type: 'textarea',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.marketing_label',
    label: 'aacp.system_settings.consent.marketing_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.marketing_desc',
    label: 'aacp.system_settings.consent.marketing_desc',
    type: 'textarea',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.privacy_label',
    label: 'aacp.system_settings.consent.privacy_label',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
#[CpSetting(
    key: 'consent.privacy_url',
    label: 'aacp.system_settings.consent.privacy_url',
    type: 'text',
    default: '',
    group: 'consent',
    translatable: true,
)]
final class ConsentSettings
{
}
