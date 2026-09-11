<?php

declare(strict_types=1);

namespace App\Core\Security\Dto;

use App\Core\Security\Entity\SystemTelemetryLog;

/**
 * Immutable verdict produced by ThreatAnalyzer for one HTTP request.
 */
final class ThreatResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $eventType,
        public readonly int $threatScore,
        public readonly array $details,
    ) {
    }

    public static function pageView(): self
    {
        return new self(SystemTelemetryLog::SEVERITY_INFO, SystemTelemetryLog::EVENT_PAGE_VIEW, 0, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailsJson(): array
    {
        return $this->details;
    }
}
