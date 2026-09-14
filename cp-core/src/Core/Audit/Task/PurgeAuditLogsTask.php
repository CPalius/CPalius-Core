<?php

declare(strict_types=1);

namespace App\Core\Audit\Task;

use App\Core\Audit\Repository\AuditLogRepository;
use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Settings\SettingsRegistry;

/**
 * Trims cp_audit_logs to the configured retention window.
 *
 * Scheduled at 05:12 rather than on the hour: PurgeTelemetryLogsTask runs at
 * 03:00 and PurgeLogEntriesTask at 04:00, and three bulk DELETEs contending for
 * the same disk in the same minute is how a nightly cleanup turns into a
 * nightly outage.
 */
final class PurgeAuditLogsTask
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    #[CpCronJob(schedule: '12 5 * * *', name: 'audit.purge', description: 'Purge aged audit log rows')]
    public function execute(): string
    {
        $value = $this->settings->get('audit.retention_days', 365);
        $days = \is_numeric($value) ? (int) $value : 365;
        $days = max(1, min(3650, $days));

        $deleted = $this->auditLogs->purgeOlderThan(
            (new \DateTimeImmutable())->modify(\sprintf('-%d days', $days)),
        );

        return \sprintf('Audit log: %d rows older than %d days removed.', $deleted, $days);
    }
}
