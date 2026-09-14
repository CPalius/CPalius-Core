<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Audit trail: engine toggle plus retention.
 * AACP → System Settings → Audit.
 *
 * cp_audit_logs had neither until this existed. AuditLogListener writes a row
 * for every create/update/delete on an entity marked #[Auditable], and nothing
 * ever removed one — on an active site it is the fastest-growing table in the
 * schema.
 */
#[CpSetting(
    key: 'audit.enabled',
    label: 'aacp.system_settings.audit.enabled',
    type: 'checkbox',
    // Default true, unlike telemetry's security_enabled. An audit trail that is
    // off by default is one nobody discovers they needed until after the
    // incident it would have explained.
    default: true,
    group: 'audit',
)]
#[CpSetting(
    key: 'audit.retention_days',
    label: 'aacp.system_settings.audit.retention_days',
    type: 'integer',
    // A year, not the 30 days application logs use. This data answers "who
    // changed this record and when" — a compliance question asked months later,
    // not a debugging one asked the same afternoon.
    default: 365,
    group: 'audit',
)]
final class AuditSettings
{
}
