<?php

declare(strict_types=1);

namespace App\Core\Logging\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Logging\Repository\LogEntryRepository;
use App\Core\Mail\Repository\MailLogRepository;
use App\Core\Settings\SettingsRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Purges aged watchdog and mail-log rows using configured retention windows.
 */
final class PurgeLogEntriesTask
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly LogEntryRepository $logEntries,
        private readonly MailLogRepository $mailLogs,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[CpCronJob(schedule: '0 4 * * *', name: 'logging.purge', description: 'Purge aged application and mail logs')]
    public function execute(): string
    {
        $now = new \DateTimeImmutable();
        $appDays = $this->days('logging.retention_days', 30);
        $mailDays = $this->days('mail.log_retention_days', 90);

        $appDeleted = $this->logEntries->purgeOlderThan($now->modify(sprintf('-%d days', $appDays)));
        $mailDeleted = $this->mailLogs->purgeOlderThan($now->modify(sprintf('-%d days', $mailDays)));

        return $this->translator->trans('aacp.logs.purge_result', [
            'time' => $now->format('c'),
            'app_deleted' => $appDeleted,
            'app_days' => $appDays,
            'mail_deleted' => $mailDeleted,
            'mail_days' => $mailDays,
        ]);
    }

    private function days(string $key, int $fallback): int
    {
        $value = $this->settingsRegistry->get($key, $fallback);
        $days = \is_numeric($value) ? (int) $value : $fallback;

        return max(1, min(3650, $days));
    }
}
