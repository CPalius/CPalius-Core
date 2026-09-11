<?php

declare(strict_types=1);

namespace App\Core\Security\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Settings\SettingsRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deletes expired telemetry rows per severity using the configured retention windows.
 */
final class PurgeTelemetryLogsTask
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[CpCronJob(schedule: '0 3 * * *', name: 'telemetry.purge_logs', description: 'Purge expired system telemetry logs by severity')]
    public function execute(): string
    {
        $map = [
            SystemTelemetryLog::SEVERITY_INFO => $this->days('telemetry.retention_info', 3),
            SystemTelemetryLog::SEVERITY_WARNING => $this->days('telemetry.retention_warning', 7),
            SystemTelemetryLog::SEVERITY_CRITICAL => $this->days('telemetry.retention_critical', 30),
            SystemTelemetryLog::SEVERITY_THREAT => $this->days('telemetry.retention_threat', 90),
        ];

        $now = new \DateTimeImmutable();
        $parts = [];

        foreach ($map as $severity => $days) {
            $before = $now->modify(sprintf('-%d days', $days));
            $deleted = $this->telemetryLogRepository->purgeOlderThan($severity, $before);
            $parts[] = $this->translator->trans('aacp.telemetry.purge_part', [
                'severity' => $severity,
                'deleted' => $deleted,
                'days' => $days,
            ]);
        }

        return $this->translator->trans('aacp.telemetry.purge_result', [
            'time' => $now->format('c'),
            'summary' => implode('; ', $parts),
        ]);
    }

    private function days(string $key, int $fallback): int
    {
        $value = $this->settingsRegistry->get($key, $fallback);
        $days = \is_numeric($value) ? (int) $value : $fallback;

        return max(1, min(3650, $days));
    }
}
