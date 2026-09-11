<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Telemetry engine: security scanner toggle plus per-severity retention.
 */
#[CpSetting(
    key: 'telemetry.security_enabled',
    label: 'aacp.telemetry.security_enabled',
    type: 'checkbox',
    default: false,
    group: 'telemetry',
)]
#[CpSetting(
    key: 'telemetry.retention_info',
    label: 'aacp.telemetry.retention.info',
    type: 'integer',
    default: 3,
    group: 'telemetry',
)]
#[CpSetting(
    key: 'telemetry.retention_warning',
    label: 'aacp.telemetry.retention.warning',
    type: 'integer',
    default: 7,
    group: 'telemetry',
)]
#[CpSetting(
    key: 'telemetry.retention_critical',
    label: 'aacp.telemetry.retention.critical',
    type: 'integer',
    default: 30,
    group: 'telemetry',
)]
#[CpSetting(
    key: 'telemetry.retention_threat',
    label: 'aacp.telemetry.retention.threat',
    type: 'integer',
    default: 90,
    group: 'telemetry',
)]
final class TelemetrySettings
{
}
