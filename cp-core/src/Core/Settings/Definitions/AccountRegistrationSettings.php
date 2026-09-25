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
/*
 * Independent from require_email_verification on purpose (Manifesto-style
 * separation of concerns, matching require_identity_change_approval's own
 * comment above): "ask new registrants to verify their address" and "refuse
 * login to anyone not currently verified" used to be the same checkbox,
 * which meant turning on the first also silently locked out every existing
 * account created before the setting existed — they were never asked to
 * verify anything, so isEmailVerified() reads false for them too. Defaults
 * to true so a site already relying on that combined behavior sees no
 * change; an operator who wants registration verification as a soft
 * nudge, not a login gate, can turn this off on its own.
 */
#[CpSetting(
    key: 'account.require_email_verification_at_login',
    label: 'settings.account.require_email_verification_at_login',
    type: 'checkbox',
    default: true,
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
/*
 * Identity is not an ordinary profile field. A member who can silently swap the
 * address a password reset goes to — or take over a username somebody else is
 * known by — can change who the account IS, so the default is that an
 * administrator sees the change before it takes effect. An operator who runs a
 * low-stakes site can turn it off; nobody has to turn it on to be safe.
 */
#[CpSetting(
    key: 'account.require_identity_change_approval',
    label: 'settings.account.require_identity_change_approval',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
final class AccountRegistrationSettings
{
}
