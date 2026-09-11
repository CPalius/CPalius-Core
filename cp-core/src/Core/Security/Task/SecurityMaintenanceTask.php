<?php

declare(strict_types=1);

namespace App\Core\Security\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Session\SessionRegistry;

/**
 * Housekeeping for the hardening tables.
 *
 * Expired bans must actually disappear, or a temporary ban is only temporary in
 * intent: the guard already ignores them, but the ban list would grow without
 * bound and the row scan that checks CIDR ranges would slow down with it.
 */
final class SecurityMaintenanceTask
{
    private const STALE_SESSION_DAYS = 30;

    public function __construct(
        private readonly IpBanService $ipBanService,
        private readonly SessionRegistry $sessionRegistry,
    ) {
    }

    #[CpCronJob(schedule: '15 3 * * *', name: 'security.maintenance', description: 'Purge expired IP bans and stale session records')]
    public function execute(): string
    {
        $bans = $this->ipBanService->purgeExpired();
        $sessions = $this->sessionRegistry->purgeStale(self::STALE_SESSION_DAYS);

        return sprintf('Purged %d expired IP ban(s) and %d stale session record(s).', $bans, $sessions);
    }
}
