<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Security\Dto\ThreatResult;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Security\Service\ThreatAnalyzer;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The site perimeter: ban enforcement plus the enforcing half of the WAF.
 *
 * Signature analysis runs here rather than at terminate so a matched payload can
 * actually be refused; the verdict is stashed on the Request so TelemetrySubscriber
 * reuses it instead of scanning the same request twice.
 *
 * Unlike the previous ban guard this covers /aacp as well — leaving the admin
 * surface reachable meant a banned host could keep hammering the login form. The
 * DB-less recovery console stays exempt because it is the documented way back in
 * after a self-inflicted ban, and the allowlist wins over every ban.
 */
final class RequestGuardSubscriber implements EventSubscriberInterface
{
    public const ATTR_THREAT = '_cp_threat_result';
    public const ATTR_BLOCKED = '_cp_blocked_reason';

    public const MODE_OFF = 'off';
    public const MODE_DETECT = 'detect';
    public const MODE_BLOCK = 'block';

    /**
     * The ONE documented way back in after a self-inflicted ban. Everything else
     * — the profiler and the web debug toolbar included — stays behind the ban
     * list and the host allowlist: a dev-only route that skips the perimeter is
     * still a route that skips the perimeter if the bundle is ever enabled in
     * production.
     */
    private const BAN_EXEMPT_PATH = '/aacp/recovery';

    /**
     * Skipped by the signature scanner only. The profiler's own URLs carry
     * serialised request data that trips the traversal and XSS signatures, so
     * scanning them is pure false positives — but they are still ban-checked.
     *
     * @var list<string>
     */
    private const WAF_SKIP_PREFIXES = [
        '/_wdt',
        '/_profiler',
    ];

    public function __construct(
        private readonly IpBanService $ipBanService,
        private readonly ThreatAnalyzer $threatAnalyzer,
        private readonly SettingsRegistry $settings,
        private readonly SecurityEventRecorder $recorder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            if ($this->isBanExempt($request)) {
                return;
            }

            if (!$this->isHostAllowed($request)) {
                $request->attributes->set(self::ATTR_BLOCKED, 'host');
                $event->setResponse($this->deny());

                return;
            }

            $ip = (string) ($request->getClientIp() ?: '');

            if ($ip !== '' && $this->ipBanService->isBanned($ip)) {
                $request->attributes->set(self::ATTR_BLOCKED, 'ip_ban');
                $event->setResponse($this->deny());

                return;
            }

            $mode = $this->wafMode();
            if ($mode === self::MODE_OFF || $this->isWafSkipped($request)) {
                return;
            }

            $result = $this->threatAnalyzer->analyze($request);
            $request->attributes->set(self::ATTR_THREAT, $result);

            if ($mode !== self::MODE_BLOCK || $result->threatScore < $this->blockScore()) {
                return;
            }

            $this->autoBan($ip, $result);
            $request->attributes->set(self::ATTR_BLOCKED, 'waf');
            $this->recorder->threat(
                SystemTelemetryLog::EVENT_REQUEST_BLOCKED,
                $result->threatScore,
                ['vectors' => array_keys($result->details), 'event' => $result->eventType],
                $request,
            );

            $event->setResponse($this->deny());
        } catch (\Throwable) {
            // The perimeter fails open: a broken guard must not take the site down.
        }
    }

    /**
     * A bare str_starts_with() would also exempt "/aacp/recovery-anything", so the
     * prefix has to end at a path boundary: the exemption is for one console, not
     * for every route whose name happens to begin with the same letters.
     */
    private function isBanExempt(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === self::BAN_EXEMPT_PATH || str_starts_with($path, self::BAN_EXEMPT_PATH.'/');
    }

    private function isWafSkipped(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::WAF_SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host header allowlist. An unset list allows everything, because the correct
     * host set cannot be guessed and a wrong guess is an outage; the security audit
     * reports the empty list as a finding instead.
     *
     * Entries are hostnames, optionally with a leading '*.' wildcard.
     */
    private function isHostAllowed(Request $request): bool
    {
        $raw = trim((string) ($this->settings->get('security.trusted_hosts') ?? ''));
        if ($raw === '') {
            return true;
        }

        $patterns = array_values(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []),
            static fn (string $p): bool => $p !== '',
        ));
        if ($patterns === []) {
            return true;
        }

        $host = strtolower($request->getHost());

        foreach ($patterns as $pattern) {
            $pattern = strtolower($pattern);

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if ($host === substr($suffix, 1) || str_ends_with($host, $suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    private function autoBan(string $ip, ThreatResult $result): void
    {
        $threshold = max(0, (int) $this->settings->get('security.waf_autoban_score', 95));
        if ($threshold <= 0 || $ip === '' || $result->threatScore < $threshold) {
            return;
        }

        $minutes = max(0, (int) $this->settings->get('security.waf_autoban_minutes', 1440));
        $banned = $this->ipBanService->ban(
            $ip,
            null,
            $minutes,
            'waf:'.$result->eventType,
            IpBanService::SOURCE_AUTO,
        );

        if ($banned) {
            $this->recorder->threat(SystemTelemetryLog::EVENT_IP_AUTOBAN, $result->threatScore, [
                'ip' => $ip,
                'minutes' => $minutes,
                'trigger' => $result->eventType,
            ]);
        }
    }

    private function wafMode(): string
    {
        $mode = (string) ($this->settings->get('security.waf_mode') ?? self::MODE_DETECT);

        return \in_array($mode, [self::MODE_OFF, self::MODE_DETECT, self::MODE_BLOCK], true)
            ? $mode
            : self::MODE_DETECT;
    }

    private function blockScore(): int
    {
        $score = (int) $this->settings->get('security.waf_block_score', 80);

        return $score > 0 ? $score : 80;
    }

    /**
     * Deliberately terse and identical for both causes: a probe should not learn
     * whether it tripped the ban list or a signature.
     */
    private function deny(): Response
    {
        return new Response('Forbidden', Response::HTTP_FORBIDDEN, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
