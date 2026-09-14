<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Audit\Repository\AuditLogRepository;
use App\Core\Logging\Repository\LogEntryRepository;
use App\Core\Mail\Repository\MailLogRepository;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Repository\TelemetryLogRepository;

/**
 * One place that knows every log store and how to empty it.
 *
 * Four repositories, four different shapes: two Doctrine, one raw DBAL, one
 * that purges per severity. The panel should not have to know any of that, and
 * neither should the next store that gets added — it registers here and the
 * screen picks it up.
 */
final class LogPurgeService
{
    public const STORE_APP = 'app';
    public const STORE_MAIL = 'mail';
    public const STORE_TELEMETRY = 'telemetry';
    public const STORE_AUDIT = 'audit';

    /** @var list<string> */
    public const STORES = [self::STORE_APP, self::STORE_MAIL, self::STORE_TELEMETRY, self::STORE_AUDIT];

    public function __construct(
        private readonly LogEntryRepository $logEntries,
        private readonly MailLogRepository $mailLogs,
        private readonly TelemetryLogRepository $telemetry,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    public function isKnownStore(string $store): bool
    {
        return \in_array($store, self::STORES, true);
    }

    /**
     * Row count per store, for the screen.
     *
     * Shown before anything is deleted: "delete 4.2M rows" and "delete 12 rows"
     * are different decisions and the operator should see which one they are
     * about to make.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            self::STORE_APP => $this->safeCount(fn (): int => $this->logEntries->countAll()),
            self::STORE_MAIL => $this->safeCount(fn (): int => $this->mailLogs->countAll()),
            self::STORE_TELEMETRY => $this->safeCount(fn (): int => $this->telemetry->countAll()),
            self::STORE_AUDIT => $this->safeCount(fn (): int => $this->auditLogs->countAll()),
        ];
    }

    /**
     * Empties one store, or every store when $store is null.
     *
     * @return array<string, int> deleted rows per store
     */
    public function purge(?string $store = null): array
    {
        $targets = $store === null ? self::STORES : [$store];
        $deleted = [];

        foreach ($targets as $target) {
            $deleted[$target] = match ($target) {
                self::STORE_APP => $this->logEntries->purgeAll(),
                self::STORE_MAIL => $this->mailLogs->purgeAll(),
                self::STORE_TELEMETRY => $this->telemetry->purgeAll(),
                self::STORE_AUDIT => $this->auditLogs->purgeAll(),
                default => 0,
            };
        }

        return $deleted;
    }

    /**
     * Applies each store's configured retention window — the same work the
     * nightly cron does, triggered by hand.
     *
     * This is the button an operator actually wants most of the time: it frees
     * space without throwing away the recent history they may still need.
     *
     * @param array<string, int> $telemetryDays severity => retention in days
     *
     * @return array<string, int>
     */
    public function purgeByRetention(int $appDays, int $mailDays, int $auditDays, array $telemetryDays): array
    {
        $now = new \DateTimeImmutable();

        $deleted = [
            self::STORE_APP => $this->logEntries->purgeOlderThan($now->modify(\sprintf('-%d days', $appDays))),
            self::STORE_MAIL => $this->mailLogs->purgeOlderThan($now->modify(\sprintf('-%d days', $mailDays))),
            self::STORE_AUDIT => $this->auditLogs->purgeOlderThan($now->modify(\sprintf('-%d days', $auditDays))),
            self::STORE_TELEMETRY => 0,
        ];

        foreach ($telemetryDays as $severity => $days) {
            $deleted[self::STORE_TELEMETRY] += $this->telemetry->purgeOlderThan(
                (string) $severity,
                $now->modify(\sprintf('-%d days', (int) $days)),
            );
        }

        return $deleted;
    }

    /**
     * A count is decoration; a table that cannot be counted — because a
     * migration has not run yet, say — must not take down the screen that
     * offers to empty it.
     *
     * @param callable(): int $counter
     */
    private function safeCount(callable $counter): int
    {
        try {
            return $counter();
        } catch (\Throwable) {
            return -1;
        }
    }
}
