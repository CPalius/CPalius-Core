<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single writer for hardening-layer events, so bans, lockouts, flood hits and CSP
 * violations all land in the same telemetry stream the AACP console already reads.
 *
 * Recording is best-effort by contract: a defence that fails to log still defends.
 */
final class SecurityEventRecorder
{
    public function __construct(
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(
        string $eventType,
        string $severity,
        int $threatScore,
        array $details = [],
        ?Request $request = null,
        ?int $userId = null,
    ): void {
        try {
            $request ??= $this->requestStack->getMainRequest();

            if ($userId === null) {
                $user = $this->security->getUser();
                $userId = $user instanceof User ? $user->getId() : null;
            }

            $this->telemetryLogRepository->insertRow(
                $request?->getClientIp() ?: '0.0.0.0',
                $userId,
                $request?->getMethod() ?? 'CLI',
                $request?->getRequestUri() ?? '-',
                (string) ($request?->headers->get('User-Agent') ?? ''),
                $severity,
                $eventType,
                $threatScore,
                $details,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Security event log write failed.', [
                'event' => $eventType,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    public function threat(string $eventType, int $threatScore, array $details = [], ?Request $request = null): void
    {
        $this->record($eventType, SystemTelemetryLog::SEVERITY_THREAT, $threatScore, $details, $request);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function warning(string $eventType, array $details = [], ?Request $request = null, ?int $userId = null): void
    {
        $this->record($eventType, SystemTelemetryLog::SEVERITY_WARNING, 30, $details, $request, $userId);
    }
}
