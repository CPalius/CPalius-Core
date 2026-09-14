<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Application watchdog + mail log retention.
 * AACP → System Settings → Logging.
 */
#[CpSetting(
    key: 'logging.min_level',
    label: 'aacp.system_settings.logging.min_level',
    type: 'select',
    default: 'warning',
    variants: [
        'debug' => 'aacp.system_settings.logging.level_debug',
        'info' => 'aacp.system_settings.logging.level_info',
        'warning' => 'aacp.system_settings.logging.level_warning',
        'error' => 'aacp.system_settings.logging.level_error',
        'critical' => 'aacp.system_settings.logging.level_critical',
    ],
    group: 'logging',
)]
#[CpSetting(
    key: 'logging.retention_days',
    label: 'aacp.system_settings.logging.retention_days',
    type: 'integer',
    default: 30,
    group: 'logging',
)]
#[CpSetting(
    key: 'mail.log_retention_days',
    label: 'aacp.system_settings.mail.log_retention_days',
    type: 'integer',
    default: 90,
    group: 'logging',
)]
final class LoggingSettings
{
}
