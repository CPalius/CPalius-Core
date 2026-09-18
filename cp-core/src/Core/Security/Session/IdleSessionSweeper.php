<?php

declare(strict_types=1);

namespace App\Core\Security\Session;

use App\Core\Security\RoleConfigManager;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Closes sessions nobody has used for a while, on a schedule rather than on the
 * visitor's next request.
 *
 * SessionGuardSubscriber enforces the same limits, but only when the browser
 * comes back — which is precisely the window somebody holding a stolen cookie is
 * operating in. This runs from cron, so an abandoned session stops being usable
 * at the configured limit whether or not its owner ever returns.
 *
 * Panel accounts get their own, shorter limit: the cost of a forgotten open tab
 * is not the same for a member's profile as it is for /aacp.
 */
final class IdleSessionSweeper
{
    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly SettingsRegistry $settings,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('security.session_sweep_enabled', true);
    }

    /**
     * @return int sessions revoked
     */
    public function revokeIdle(?\DateTimeImmutable $now = null): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $now ??= new \DateTimeImmutable();

        return $this->sweepEveryone($now) + $this->sweepPrivileged($now);
    }

    /** Drops rows for sessions long past any usefulness, revoked or not. */
    public function purge(): int
    {
        return $this->registry->purgeStale($this->purgeDays());
    }

    private function sweepEveryone(\DateTimeImmutable $now): int
    {
        $minutes = $this->minutes('security.session_idle_minutes');

        return $minutes > 0 ? $this->registry->revokeIdleBefore($now->modify('-'.$minutes.' minutes')) : 0;
    }

    /**
     * Only worth a second pass when the privileged limit is actually shorter;
     * otherwise the first sweep already covered these accounts.
     */
    private function sweepPrivileged(\DateTimeImmutable $now): int
    {
        $adminMinutes = $this->minutes('security.session_admin_idle_minutes');
        if ($adminMinutes <= 0) {
            return 0;
        }

        $globalMinutes = $this->minutes('security.session_idle_minutes');
        if ($globalMinutes > 0 && $globalMinutes <= $adminMinutes) {
            return 0;
        }

        $before = $now->modify('-'.$adminMinutes.' minutes');
        $candidates = $this->registry->idleUserIds($before);
        if ($candidates === []) {
            return 0;
        }

        $privileged = [];
        foreach ($this->userRepository->findBy(['id' => $candidates]) as $user) {
            $id = $user->getId();
            if ($id !== null && $this->isPrivileged($user)) {
                $privileged[] = $id;
            }
        }

        return $privileged === [] ? 0 : $this->registry->revokeIdleBefore($before, $privileged);
    }

    private function isPrivileged(User $user): bool
    {
        $capabilities = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        return \in_array(TwoFactorService::PRIVILEGED_CAPABILITY, $capabilities, true)
            || \in_array('*', $capabilities, true);
    }

    private function minutes(string $key): int
    {
        return max(0, (int) $this->settings->get($key, 0));
    }

    private function purgeDays(): int
    {
        return max(1, (int) $this->settings->get('security.session_purge_days', 30));
    }
}
