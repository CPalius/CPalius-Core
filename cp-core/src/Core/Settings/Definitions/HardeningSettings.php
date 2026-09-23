<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Control surface for the hardening layer. Rendered under System Settings →
 * Security (grouped by the security.* group names below). The Security Center
 * keeps the live posture audit, bans and sessions.
 */
#[CpSetting(
    key: 'security.headers_enabled',
    label: 'aacp.security.headers_enabled',
    type: 'checkbox',
    default: true,
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_mode',
    label: 'aacp.security.csp_mode',
    type: 'select',
    default: 'report',
    variants: [
        'off' => 'aacp.security.csp_mode_off',
        'report' => 'aacp.security.csp_mode_report',
        'balanced' => 'aacp.security.csp_mode_balanced',
        'strict' => 'aacp.security.csp_mode_strict',
    ],
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_script_src',
    label: 'aacp.security.csp_script_src',
    type: 'text',
    default: '',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_style_src',
    label: 'aacp.security.csp_style_src',
    type: 'text',
    default: '',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_img_src',
    label: 'aacp.security.csp_img_src',
    type: 'text',
    default: '',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_connect_src',
    label: 'aacp.security.csp_connect_src',
    type: 'text',
    default: '',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_frame_src',
    label: 'aacp.security.csp_frame_src',
    type: 'text',
    default: 'https://www.youtube-nocookie.com https://player.vimeo.com',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.csp_frame_ancestors',
    label: 'aacp.security.csp_frame_ancestors',
    type: 'text',
    default: "'self'",
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.hsts_max_age',
    label: 'aacp.security.hsts_max_age',
    type: 'integer',
    default: 0,
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.hsts_subdomains',
    label: 'aacp.security.hsts_subdomains',
    type: 'checkbox',
    default: false,
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.hsts_preload',
    label: 'aacp.security.hsts_preload',
    type: 'checkbox',
    default: false,
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.referrer_policy',
    label: 'aacp.security.referrer_policy',
    type: 'select',
    default: 'strict-origin-when-cross-origin',
    variants: [
        'no-referrer' => 'no-referrer',
        'same-origin' => 'same-origin',
        'strict-origin' => 'strict-origin',
        'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
    ],
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.permissions_policy',
    label: 'aacp.security.permissions_policy',
    type: 'text',
    default: 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    group: 'security.headers',
)]
#[CpSetting(
    key: 'security.waf_mode',
    label: 'aacp.security.waf_mode',
    type: 'select',
    default: 'detect',
    variants: [
        'off' => 'aacp.security.waf_mode_off',
        'detect' => 'aacp.security.waf_mode_detect',
        'block' => 'aacp.security.waf_mode_block',
    ],
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.waf_block_score',
    label: 'aacp.security.waf_block_score',
    type: 'integer',
    default: 80,
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.waf_autoban_score',
    label: 'aacp.security.waf_autoban_score',
    type: 'integer',
    default: 95,
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.waf_autoban_minutes',
    label: 'aacp.security.waf_autoban_minutes',
    type: 'integer',
    default: 1440,
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.ip_allowlist',
    label: 'aacp.security.ip_allowlist',
    type: 'textarea',
    default: '',
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.trusted_hosts',
    label: 'aacp.security.trusted_hosts',
    type: 'textarea',
    default: '',
    group: 'security.waf',
)]
#[CpSetting(
    key: 'security.flood_enabled',
    label: 'aacp.security.flood_enabled',
    type: 'checkbox',
    default: true,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.login_ip_limit',
    label: 'aacp.security.login_ip_limit',
    type: 'integer',
    default: 20,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.login_user_limit',
    label: 'aacp.security.login_user_limit',
    type: 'integer',
    default: 5,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.login_window_minutes',
    label: 'aacp.security.login_window_minutes',
    type: 'integer',
    default: 15,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.lockout_minutes',
    label: 'aacp.security.lockout_minutes',
    type: 'integer',
    default: 15,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.autoban_after_lockouts',
    label: 'aacp.security.autoban_after_lockouts',
    type: 'integer',
    default: 3,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.register_limit_hourly',
    label: 'aacp.security.register_limit_hourly',
    type: 'integer',
    default: 5,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.reset_limit_hourly',
    label: 'aacp.security.reset_limit_hourly',
    type: 'integer',
    default: 5,
    group: 'security.flood',
)]
#[CpSetting(
    key: 'security.password_min_length',
    label: 'aacp.security.password_min_length',
    type: 'integer',
    default: 10,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_required_classes',
    label: 'aacp.security.password_required_classes',
    type: 'integer',
    default: 3,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_breach_check',
    label: 'aacp.security.password_breach_check',
    type: 'checkbox',
    default: true,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_breach_fail_closed',
    label: 'aacp.security.password_breach_fail_closed',
    type: 'checkbox',
    default: false,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_history_depth',
    label: 'aacp.security.password_history_depth',
    type: 'integer',
    default: 5,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_max_age_days',
    label: 'aacp.security.password_max_age_days',
    type: 'integer',
    default: 0,
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.password_denylist',
    label: 'aacp.security.password_denylist',
    type: 'textarea',
    default: '',
    group: 'security.password',
)]
#[CpSetting(
    key: 'security.twofactor_enabled',
    label: 'aacp.security.twofactor_enabled',
    type: 'checkbox',
    default: true,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.twofactor_enforce_privileged',
    label: 'aacp.security.twofactor_enforce_privileged',
    type: 'checkbox',
    default: false,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.twofactor_drift',
    label: 'aacp.security.twofactor_drift',
    type: 'integer',
    default: 1,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.twofactor_methods',
    label: 'aacp.security.twofactor_methods',
    type: 'select',
    default: 'both',
    variants: [
        'both' => 'aacp.security.twofactor_methods_both',
        'app' => 'aacp.security.twofactor_methods_app',
        'email' => 'aacp.security.twofactor_methods_email',
    ],
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.twofactor_email_ttl_minutes',
    label: 'aacp.security.twofactor_email_ttl_minutes',
    type: 'integer',
    default: 10,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.twofactor_email_resend_seconds',
    label: 'aacp.security.twofactor_email_resend_seconds',
    type: 'integer',
    default: 60,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.login_email_code',
    label: 'aacp.security.login_email_code',
    type: 'checkbox',
    default: true,
    group: 'security.twofactor',
)]
#[CpSetting(
    key: 'security.aacp_gate_enabled',
    label: 'aacp.security.aacp_gate_enabled',
    type: 'checkbox',
    default: false,
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.aacp_gate_question',
    label: 'aacp.security.aacp_gate_question',
    type: 'text',
    default: '',
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.aacp_gate_answer',
    label: 'aacp.security.aacp_gate_answer',
    type: 'password',
    default: '',
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.aacp_gate_ttl_minutes',
    label: 'aacp.security.aacp_gate_ttl_minutes',
    type: 'integer',
    default: 120,
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.aacp_gate_attempts',
    label: 'aacp.security.aacp_gate_attempts',
    type: 'integer',
    default: 3,
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.aacp_gate_lockout_minutes',
    label: 'aacp.security.aacp_gate_lockout_minutes',
    type: 'integer',
    default: 15,
    group: 'security.aacp',
)]
#[CpSetting(
    key: 'security.session_idle_minutes',
    label: 'aacp.security.session_idle_minutes',
    type: 'integer',
    default: 60,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_admin_idle_minutes',
    label: 'aacp.security.session_admin_idle_minutes',
    type: 'integer',
    default: 20,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_sweep_enabled',
    label: 'aacp.security.session_sweep_enabled',
    type: 'checkbox',
    default: true,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_purge_days',
    label: 'aacp.security.session_purge_days',
    type: 'integer',
    default: 30,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_absolute_hours',
    label: 'aacp.security.session_absolute_hours',
    type: 'integer',
    default: 0,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_bind_user_agent',
    label: 'aacp.security.session_bind_user_agent',
    type: 'checkbox',
    default: true,
    group: 'security.session',
)]
#[CpSetting(
    key: 'security.session_bind_ip',
    label: 'aacp.security.session_bind_ip',
    type: 'checkbox',
    default: false,
    group: 'security.session',
)]
final class HardeningSettings
{
}
