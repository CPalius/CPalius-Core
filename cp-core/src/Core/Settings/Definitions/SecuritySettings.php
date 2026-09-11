<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Captcha provider and form-protection settings. AACP → System → Security.
 */
#[CpSetting(
    key: 'security.captcha_provider',
    label: 'aacp.system_settings.security.captcha_provider',
    type: 'select',
    default: 'none',
    variants: [
        'none' => 'aacp.system_settings.security.provider_none',
        'recaptcha' => 'aacp.system_settings.security.provider_recaptcha',
        'turnstile' => 'aacp.system_settings.security.provider_turnstile',
        'hcaptcha' => 'aacp.system_settings.security.provider_hcaptcha',
    ],
    group: 'security',
)]
#[CpSetting(
    key: 'security.captcha_on_register',
    label: 'aacp.system_settings.security.captcha_on_register',
    type: 'checkbox',
    default: true,
    group: 'security',
)]
#[CpSetting(
    key: 'security.captcha_on_login',
    label: 'aacp.system_settings.security.captcha_on_login',
    type: 'checkbox',
    default: false,
    group: 'security',
)]
#[CpSetting(
    key: 'security.recaptcha_site_key',
    label: 'aacp.system_settings.security.recaptcha_site_key',
    type: 'text',
    default: '',
    group: 'security',
)]
#[CpSetting(
    key: 'security.recaptcha_secret_key',
    label: 'aacp.system_settings.security.recaptcha_secret_key',
    type: 'password',
    default: '',
    group: 'security',
)]
#[CpSetting(
    key: 'security.recaptcha_version',
    label: 'aacp.system_settings.security.recaptcha_version',
    type: 'select',
    default: 'v2',
    variants: [
        'v2' => 'aacp.system_settings.security.recaptcha_v2',
        'v3' => 'aacp.system_settings.security.recaptcha_v3',
    ],
    group: 'security',
)]
#[CpSetting(
    key: 'security.recaptcha_score_threshold',
    label: 'aacp.system_settings.security.recaptcha_score_threshold',
    type: 'text',
    default: '0.5',
    group: 'security',
)]
#[CpSetting(
    key: 'security.turnstile_site_key',
    label: 'aacp.system_settings.security.turnstile_site_key',
    type: 'text',
    default: '',
    group: 'security',
)]
#[CpSetting(
    key: 'security.turnstile_secret_key',
    label: 'aacp.system_settings.security.turnstile_secret_key',
    type: 'password',
    default: '',
    group: 'security',
)]
#[CpSetting(
    key: 'security.hcaptcha_site_key',
    label: 'aacp.system_settings.security.hcaptcha_site_key',
    type: 'text',
    default: '',
    group: 'security',
)]
#[CpSetting(
    key: 'security.hcaptcha_secret_key',
    label: 'aacp.system_settings.security.hcaptcha_secret_key',
    type: 'password',
    default: '',
    group: 'security',
)]
final class SecuritySettings
{
}
