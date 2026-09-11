<?php

declare(strict_types=1);

namespace App\Core\Security\Session;

use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Session lifetime and integrity rules that PHP's own session GC does not cover:
 * an idle cut-off, an absolute cap regardless of activity, binding to the browser
 * that created the session, and honouring a remote revocation.
 *
 * Every rule is opt-in through settings, defaulting to the least surprising
 * behaviour, because an over-eager session rule is indistinguishable from a bug
 * to the person being logged out.
 */
final class SessionGuardSubscriber implements EventSubscriberInterface
{
    private const KEY_STARTED = '_cp_session_started_at';
    private const KEY_SEEN = '_cp_session_last_seen_at';
    private const KEY_FINGERPRINT = '_cp_session_fingerprint';
    private const KEY_IP = '_cp_session_ip';

    /** Registry writes are throttled so an active session is not one UPDATE per request. */
    private const TOUCH_INTERVAL = 60;

    /** @var list<string> */
    private const SKIP_PREFIXES = ['/_wdt', '/_profiler', '/assets/', '/build/', '/uploads/'];

    public function __construct(
        private readonly Security $security,
        private readonly SessionRegistry $registry,
        private readonly SettingsRegistry $settings,
        private readonly SecurityEventRecorder $recorder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall (8) so the token exists, before the 2FA gate (6).
            KernelEvents::REQUEST => ['onKernelRequest', 7],
            LogoutEvent::class => 'onLogout',
        ];
    }

    /**
     * Drops the registry row on an explicit sign-out, so the session list shows
     * what is actually still usable rather than every session ever created.
     */
    public function onLogout(LogoutEvent $event): void
    {
        try {
            $request = $event->getRequest();
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $this->registry->forget($request->getSession()->getId());
            }
        } catch (\Throwable) {
            // Stale rows are pruned by the maintenance task.
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            if ($this->shouldSkip($request) || !$request->hasSession()) {
                return;
            }

            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return;
            }

            $session = $request->getSession();
            if (!$session->isStarted()) {
                return;
            }

            $reason = $this->violation($session, $request);
            if ($reason !== null) {
                $this->terminate($session, $request, $user, $reason);
                $event->setResponse(new RedirectResponse('/hesap/giris'));

                return;
            }

            $this->stamp($session, $request, $user);
        } catch (\Throwable) {
            // Session hardening never blocks the request path on its own failure.
        }
    }

    private function violation(SessionInterface $session, Request $request): ?string
    {
        if ($this->registry->isRevoked($session->getId())) {
            return 'revoked';
        }

        $now = time();

        $idleLimit = $this->minutes('security.session_idle_minutes');
        $lastSeen = $session->get(self::KEY_SEEN);
        if ($idleLimit > 0 && \is_int($lastSeen) && $now - $lastSeen > $idleLimit) {
            return 'idle_timeout';
        }

        $absoluteLimit = $this->hours('security.session_absolute_hours');
        $startedAt = $session->get(self::KEY_STARTED);
        if ($absoluteLimit > 0 && \is_int($startedAt) && $now - $startedAt > $absoluteLimit) {
            return 'absolute_timeout';
        }

        if ((bool) $this->settings->get('security.session_bind_user_agent', true)) {
            $stored = $session->get(self::KEY_FINGERPRINT);
            if (\is_string($stored) && !hash_equals($stored, $this->registry->fingerprint($request))) {
                return 'user_agent_changed';
            }
        }

        if ((bool) $this->settings->get('security.session_bind_ip', false)) {
            $storedIp = $session->get(self::KEY_IP);
            $currentIp = (string) ($request->getClientIp() ?: '');
            if (\is_string($storedIp) && $storedIp !== '' && $currentIp !== '' && $storedIp !== $currentIp) {
                return 'ip_changed';
            }
        }

        return null;
    }

    private function stamp(SessionInterface $session, Request $request, User $user): void
    {
        $now = time();

        if (!\is_int($session->get(self::KEY_STARTED))) {
            $session->set(self::KEY_STARTED, $now);
        }
        if (!\is_string($session->get(self::KEY_FINGERPRINT))) {
            $session->set(self::KEY_FINGERPRINT, $this->registry->fingerprint($request));
        }
        if (!\is_string($session->get(self::KEY_IP))) {
            $session->set(self::KEY_IP, (string) ($request->getClientIp() ?: ''));
        }

        $lastSeen = $session->get(self::KEY_SEEN);
        if (\is_int($lastSeen) && $now - $lastSeen < self::TOUCH_INTERVAL) {
            return;
        }

        $session->set(self::KEY_SEEN, $now);
        $this->registry->touch($user, $session->getId(), $request);
    }

    private function terminate(SessionInterface $session, Request $request, User $user, string $reason): void
    {
        $sessionId = $session->getId();

        $this->recorder->record(
            SystemTelemetryLog::EVENT_SESSION_REVOKED,
            SystemTelemetryLog::SEVERITY_WARNING,
            35,
            ['reason' => $reason],
            $request,
            $user->getId(),
        );

        $this->registry->forget($sessionId);
        $session->invalidate();
    }

    private function shouldSkip(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function minutes(string $key): int
    {
        return max(0, (int) $this->settings->get($key, 0)) * 60;
    }

    private function hours(string $key): int
    {
        return max(0, (int) $this->settings->get($key, 0)) * 3600;
    }
}
