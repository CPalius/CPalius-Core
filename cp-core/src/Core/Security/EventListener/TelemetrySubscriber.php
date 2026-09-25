<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Analytics\VisitorStatsRecorder;
use App\Core\Security\Dto\ThreatResult;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Http\LoginTargetPath;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\ThreatAnalyzer;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Persists request telemetry after the response is sent. Failures never bubble.
 */
final class TelemetrySubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const SKIP_PREFIXES = [
        '/_wdt',
        '/_profiler',
        '/assets/',
        '/uploads/',
        '/build/',
    ];

    /** @var list<string> */
    private const SKIP_PATHS = [
        '/aacp/system/metrics',
        '/aacp/telemetry/live-feed',
        '/aacp/telemetry/ban-ip',
        '/favicon.ico',
    ];

    public function __construct(
        private readonly ThreatAnalyzer $threatAnalyzer,
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly VisitorStatsRecorder $visitorStatsRecorder,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        try {
            $request = $event->getRequest();
            if (!$this->shouldLog($request)) {
                return;
            }

            $securityOn = (bool) $this->settingsRegistry->get('telemetry.security_enabled', false);
            if (!$securityOn) {
                $path = $request->getPathInfo();
                if (str_starts_with($path, '/aacp') || str_starts_with($path, '/admin')) {
                    return;
                }
            }

            $result = $this->resolveResult($request, $securityOn);
            $ip = (string) ($request->getClientIp() ?: '0.0.0.0');

            // An ordinary page view — the overwhelming majority of requests,
            // in both modes: ThreatAnalyzer::analyze() only escalates away
            // from EVENT_PAGE_VIEW when it actually finds something — is
            // counted, not logged as a row. cp_system_telemetry_logs now
            // holds only what's worth inspecting individually: real security
            // signals. This is also what stops "clear telemetry" from wiping
            // visitor history — they no longer share a table.
            if ($result->eventType === SystemTelemetryLog::EVENT_PAGE_VIEW) {
                $this->visitorStatsRecorder->record($ip);

                return;
            }

            $user = $this->security->getUser();
            $userId = $user instanceof User ? $user->getId() : null;

            $this->telemetryLogRepository->insertRow(
                $ip,
                $userId,
                $request->getMethod(),
                $request->getRequestUri(),
                (string) $request->headers->get('User-Agent', ''),
                $result->severity,
                $result->eventType,
                $result->threatScore,
                $result->details,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Telemetry log write failed.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * RequestGuardSubscriber already scanned this request when the WAF is on;
     * re-running the signature set here would double the cost for nothing.
     */
    private function resolveResult(Request $request, bool $securityOn): ThreatResult
    {
        $stashed = $request->attributes->get(RequestGuardSubscriber::ATTR_THREAT);
        if ($stashed instanceof ThreatResult) {
            return $stashed;
        }

        return $securityOn ? $this->threatAnalyzer->analyze($request) : ThreatResult::pageView();
    }

    private function shouldLog(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if (\in_array($path, self::SKIP_PATHS, true)) {
            return false;
        }

        if (LoginTargetPath::isMachineRequest($request)) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (\in_array($ext, ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf'], true)) {
            return false;
        }

        return true;
    }
}
