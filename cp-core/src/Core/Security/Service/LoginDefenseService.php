<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Security\CaptchaService;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Flood\FloodService;
use App\Core\Settings\SettingsRegistry;

/**
 * Brute-force defence for the login form.
 *
 * Two independent counters are kept on purpose: per-account (stops password
 * spraying against one privileged mailbox) and per-IP (stops credential stuffing
 * across many accounts from one host). Either one can lock, and repeated lockouts
 * from the same host escalate to a timed IP ban.
 *
 * Login counters fail open when the cache is unreachable — a Redis outage must not
 * lock every operator out of their own site.
 */
final class LoginDefenseService
{
    public function __construct(
        private readonly FloodService $flood,
        private readonly IpBanService $ipBanService,
        private readonly SettingsRegistry $settings,
        private readonly SecurityEventRecorder $recorder,
        private readonly CaptchaService $captchaService,
    ) {
    }

    public function isBlocked(string $ip, string $identifier): bool
    {
        return $this->lockedUntil($ip, $identifier) !== null;
    }

    /**
     * @return int|null unix timestamp the block lifts at, or null when not blocked
     */
    public function lockedUntil(string $ip, string $identifier): ?int
    {
        $account = $identifier !== ''
            ? $this->flood->lockedUntil(FloodService::EVENT_LOGIN_USER, $identifier)
            : null;
        $host = $ip !== ''
            ? $this->flood->lockedUntil(FloodService::EVENT_LOGIN_IP, $ip)
            : null;

        $candidates = array_filter([$account, $host]);

        return $candidates === [] ? null : max($candidates);
    }

    /**
     * Progressive defence: a host that has already failed half of its allowance
     * must solve a captcha before it gets locked out entirely.
     *
     * Keyed on the IP alone because the login form has to decide whether to render
     * the widget before it knows which account is being targeted.
     */
    public function captchaRequiredOnLogin(string $ip): bool
    {
        if ($this->captchaService->enabledOnLogin()) {
            return true;
        }

        if ($ip === '' || $this->captchaService->resolveReadyProvider() === CaptchaService::PROVIDER_NONE) {
            return false;
        }

        $threshold = (int) ceil($this->ipLimit() / 2);

        return $this->flood->count(FloodService::EVENT_LOGIN_IP, $ip, $this->window()) >= $threshold;
    }

    public function registerFailure(string $ip, string $identifier): void
    {
        $window = $this->window();
        $lockSeconds = $this->lockoutMinutes() * 60;

        // A lockout is an edge, not a level. Every failed attempt that arrives
        // while the lock is already held used to re-lock and re-escalate, so the
        // "repeated lockouts" counter counted attempts instead of lockouts: three
        // extra tries against an already-locked account were enough to auto-ban the
        // source, which behind a shared NAT means one forgetful employee takes the
        // whole office offline for a day. Only the transition counts now, and it
        // escalates once per failure even when both counters trip together.
        $escalated = false;

        if ($identifier !== '') {
            $attempts = $this->flood->register(FloodService::EVENT_LOGIN_USER, $identifier, $window);
            $alreadyLocked = $this->flood->lockedUntil(FloodService::EVENT_LOGIN_USER, $identifier) !== null;

            if (!$alreadyLocked && $attempts >= $this->userLimit()) {
                $this->flood->lock(FloodService::EVENT_LOGIN_USER, $identifier, $lockSeconds);
                $this->recorder->record(
                    SystemTelemetryLog::EVENT_LOGIN_LOCKOUT,
                    SystemTelemetryLog::SEVERITY_CRITICAL,
                    60,
                    ['scope' => 'account', 'attempts' => $attempts, 'minutes' => $this->lockoutMinutes()],
                );
                $this->escalate($ip);
                $escalated = true;
            }
        }

        if ($ip === '') {
            return;
        }

        $hostAttempts = $this->flood->register(FloodService::EVENT_LOGIN_IP, $ip, $window);
        if ($this->flood->lockedUntil(FloodService::EVENT_LOGIN_IP, $ip) !== null
            || $hostAttempts < $this->ipLimit()
        ) {
            return;
        }

        $this->flood->lock(FloodService::EVENT_LOGIN_IP, $ip, $lockSeconds);
        $this->recorder->record(
            SystemTelemetryLog::EVENT_LOGIN_LOCKOUT,
            SystemTelemetryLog::SEVERITY_CRITICAL,
            70,
            ['scope' => 'ip', 'attempts' => $hostAttempts, 'minutes' => $this->lockoutMinutes()],
        );

        if (!$escalated) {
            $this->escalate($ip);
        }
    }

    public function registerSuccess(string $ip, string $identifier): void
    {
        if ($identifier !== '') {
            $this->flood->clear(FloodService::EVENT_LOGIN_USER, $identifier);
        }
        if ($ip !== '') {
            $this->flood->clear(FloodService::EVENT_LOGIN_IP, $ip);
        }
    }

    /**
     * A host that keeps triggering lockouts is no longer a forgetful user.
     * The counter window is deliberately long (24h) so slow, patient spraying
     * still adds up to a ban.
     */
    private function escalate(string $ip): void
    {
        $threshold = max(0, (int) $this->settings->get('security.autoban_after_lockouts', 3));
        if ($threshold <= 0 || $ip === '') {
            return;
        }

        $lockouts = $this->flood->register(FloodService::EVENT_LOCKOUT_COUNT, $ip, 86400);
        if ($lockouts < $threshold) {
            return;
        }

        $minutes = max(0, (int) $this->settings->get('security.waf_autoban_minutes', 1440));
        if ($this->ipBanService->ban($ip, null, $minutes, 'login_bruteforce', IpBanService::SOURCE_AUTO)) {
            $this->recorder->threat(SystemTelemetryLog::EVENT_IP_AUTOBAN, 90, [
                'ip' => $ip,
                'lockouts' => $lockouts,
                'minutes' => $minutes,
                'trigger' => 'login_bruteforce',
            ]);
        }
    }

    private function window(): int
    {
        return max(1, (int) $this->settings->get('security.login_window_minutes', 15)) * 60;
    }

    private function userLimit(): int
    {
        $limit = (int) $this->settings->get('security.login_user_limit', 5);

        return $limit > 0 ? $limit : 5;
    }

    private function ipLimit(): int
    {
        $limit = (int) $this->settings->get('security.login_ip_limit', 20);

        return $limit > 0 ? $limit : 20;
    }

    private function lockoutMinutes(): int
    {
        $minutes = (int) $this->settings->get('security.lockout_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }
}
