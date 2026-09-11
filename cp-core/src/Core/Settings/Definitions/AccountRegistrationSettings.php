<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Account registration flow settings. Studio → Module settings → account group.
 */
#[CpSetting(
    key: 'account.registration_enabled',
    label: 'settings.account.registration_enabled',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_email_verification',
    label: 'settings.account.require_email_verification',
    type: 'checkbox',
    default: false,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_admin_approval',
    label: 'settings.account.require_admin_approval',
    type: 'checkbox',
    default: false,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.username_required',
    label: 'settings.account.username_required',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.show_first_name',
    label: 'settings.account.show_first_name',
    type: 'select',
    default: '2',
    variants: [
        '0' => 'settings.account.field_hidden',
        '1' => 'settings.account.field_optional',
        '2' => 'settings.account.field_required',
    ],
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.show_last_name',
    label: 'settings.account.show_last_name',
    type: 'select',
    default: '2',
    variants: [
        '0' => 'settings.account.field_hidden',
        '1' => 'settings.account.field_optional',
        '2' => 'settings.account.field_required',
    ],
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_terms',
    label: 'settings.account.require_terms',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
final class AccountRegistrationSettings
{
}
