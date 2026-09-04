<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * SMTP / outbound mail settings. AACP → System → Email.
 */
#[CpSetting(
    key: 'mail.enabled',
    label: 'aacp.system_settings.mail.enabled',
    type: 'checkbox',
    default: false,
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.smtp_host',
    label: 'aacp.system_settings.mail.smtp_host',
    type: 'text',
    default: '',
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.smtp_port',
    label: 'aacp.system_settings.mail.smtp_port',
    type: 'integer',
    default: 587,
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.smtp_encryption',
    label: 'aacp.system_settings.mail.smtp_encryption',
    type: 'select',
    default: 'tls',
    variants: [
        'none' => 'aacp.system_settings.mail.encryption_none',
        'tls' => 'aacp.system_settings.mail.encryption_tls',
        'ssl' => 'aacp.system_settings.mail.encryption_ssl',
    ],
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.smtp_user',
    label: 'aacp.system_settings.mail.smtp_user',
    type: 'text',
    default: '',
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.smtp_password',
    label: 'aacp.system_settings.mail.smtp_password',
    type: 'password',
    default: '',
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.from_email',
    label: 'aacp.system_settings.mail.from_email',
    type: 'text',
    default: '',
    group: 'mail',
)]
#[CpSetting(
    key: 'mail.from_name',
    label: 'aacp.system_settings.mail.from_name',
    type: 'text',
    default: '',
    group: 'mail',
)]
final class MailSettings
{
}
