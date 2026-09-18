<?php

declare(strict_types=1);

namespace App\Core\Security\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Session\IdleSessionSweeper;

/**
 * Housekeeping for the hardening tables.
 *
 * Expired bans must actually disappear, or a temporary ban is only temporary in
 * intent: the guard already ignores them, but the ban list would grow without
 * bound and the row scan that checks CIDR ranges would slow down with it.
 */
final class SecurityMaintenanceTask
{
    public function __construct(
        private readonly IpBanService $ipBanService,
        private readonly IdleSessionSweeper $sessionSweeper,
    ) {
    }

    #[CpCronJob(schedule: '15 3 * * *', name: 'security.maintenance', description: 'Purge expired IP bans and stale session records')]
    public function execute(): string
    {
        $bans = $this->ipBanService->purgeExpired();
        $revoked = $this->sessionSweeper->revokeIdle();
        $purged = $this->sessionSweeper->purge();

        return sprintf(
            'Purged %d expired IP ban(s), revoked %d idle session(s) and removed %d stale session record(s).',
            $bans,
            $revoked,
            $purged,
        );
    }

    /**
     * Idle sessions have to close on the clock, not once a day: a session left
     * open at 03:16 would otherwise stay usable for almost 24 hours whatever the
     * idle limit says.
     */
    #[CpCronJob(schedule: '*/5 * * * *', name: 'security.session_sweep', description: 'Revoke sessions idle past the configured limit')]
    public function sweepSessions(): string
    {
        if (!$this->sessionSweeper->isEnabled()) {
            return 'Idle session sweep is disabled.';
        }

        return sprintf('Revoked %d idle session(s).', $this->sessionSweeper->revokeIdle());
    }
}
