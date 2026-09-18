<?php

declare(strict_types=1);

namespace App\Core\Security\Gate;

use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Shared-secret question asked once per session before the panel opens.
 *
 * This is deliberately NOT a second factor: a question everybody with panel
 * access answers the same way cannot be one. What it buys is that a stolen
 * session cookie, or a browser left unlocked on a logged-in account, does not
 * hand over /aacp as well — the attacker has the site, not the console.
 *
 * Two properties keep it from becoming a lockout:
 *   - an enabled-but-unconfigured gate is inert (isActive() is false), so an
 *     operator who ticks the box and forgets the answer is not shut out;
 *   - /aacp/recovery stays exempt, as it is for every other guard.
 */
final class AacpGate
{
    public const SESSION_PASSED_AT = '_cp_aacp_gate_passed_at';
    public const GATE_PATH = '/aacp/gate';

    private const DEFAULT_TTL_MINUTES = 120;
    private const DEFAULT_ATTEMPTS = 3;
    private const DEFAULT_LOCKOUT_MINUTES = 15;

    /** Window the attempt counter slides over; the hard lock does the real blocking. */
    private const ATTEMPT_WINDOW = 900;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly FloodService $flood,
        private readonly SecurityEventRecorder $recorder,
    ) {
    }

    /**
     * True only when the operator both switched the gate on AND supplied a
     * question and an answer. Everything else treats it as off.
     */
    public function isActive(): bool
    {
        return (bool) $this->settings->get('security.aacp_gate_enabled', false) && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        return $this->question() !== '' && $this->answer() !== '';
    }

    public function question(): string
    {
        return trim((string) $this->settings->get('security.aacp_gate_question', ''));
    }

    public function isPassed(SessionInterface $session): bool
    {
        $passedAt = $session->get(self::SESSION_PASSED_AT);
        if (!\is_int($passedAt)) {
            return false;
        }

        $ttl = $this->ttlSeconds();

        // A zero TTL means "for as long as the session lives"; the session guard
        // still expires it on idle, so this is not an unbounded pass.
        return $ttl === 0 || time() - $passedAt <= $ttl;
    }

    public function markPassed(SessionInterface $session): void
    {
        $session->set(self::SESSION_PASSED_AT, time());
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::SESSION_PASSED_AT);
    }

    /**
     * Compares case-insensitively and ignores surrounding whitespace: an answer
     * typed months later is remembered as words, not as an exact byte string.
     */
    public function verify(User $user, string $submitted, ?Request $request = null): bool
    {
        $expected = $this->answer();
        if ($expected === '') {
            return false;
        }

        if (hash_equals($this->normalize($expected), $this->normalize($submitted))) {
            $this->flood->clear(FloodService::EVENT_AACP_GATE, $this->identifier($user));

            return true;
        }

        $this->registerFailure($user, $request);

        return false;
    }

    /**
     * @return int|null unix timestamp the lockout lifts at, or null when not locked
     */
    public function lockedUntil(User $user): ?int
    {
        return $this->flood->lockedUntil(FloodService::EVENT_AACP_GATE, $this->identifier($user));
    }

    public function remainingAttempts(User $user): int
    {
        $limit = $this->attemptLimit();
        if ($limit <= 0) {
            return 0;
        }

        $used = $this->flood->count(FloodService::EVENT_AACP_GATE, $this->identifier($user), self::ATTEMPT_WINDOW);

        return max(0, $limit - $used);
    }

    private function registerFailure(User $user, ?Request $request): void
    {
        $identifier = $this->identifier($user);
        $used = $this->flood->register(FloodService::EVENT_AACP_GATE, $identifier, self::ATTEMPT_WINDOW);
        $limit = $this->attemptLimit();

        if ($limit > 0 && $used >= $limit) {
            $this->flood->lock(FloodService::EVENT_AACP_GATE, $identifier, $this->lockoutSeconds());
        }

        $this->recorder->record(
            SystemTelemetryLog::EVENT_AACP_GATE_FAILED,
            SystemTelemetryLog::SEVERITY_WARNING,
            45,
            ['attempts' => $used, 'limit' => $limit],
            $request,
            $user->getId(),
        );
    }

    private function answer(): string
    {
        return trim((string) $this->settings->get('security.aacp_gate_answer', ''));
    }

    private function normalize(string $value): string
    {
        // Collapse inner runs of whitespace too, so "kedi  mavi" and "kedi mavi"
        // are the same answer.
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function identifier(User $user): string
    {
        return 'user:'.($user->getId() ?? 0);
    }

    private function ttlSeconds(): int
    {
        return max(0, (int) $this->settings->get('security.aacp_gate_ttl_minutes', self::DEFAULT_TTL_MINUTES)) * 60;
    }

    private function attemptLimit(): int
    {
        return max(0, (int) $this->settings->get('security.aacp_gate_attempts', self::DEFAULT_ATTEMPTS));
    }

    private function lockoutSeconds(): int
    {
        return max(1, (int) $this->settings->get('security.aacp_gate_lockout_minutes', self::DEFAULT_LOCKOUT_MINUTES)) * 60;
    }
}
