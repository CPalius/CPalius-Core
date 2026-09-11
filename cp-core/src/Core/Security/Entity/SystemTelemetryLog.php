<?php

declare(strict_types=1);

namespace App\Core\Security\Entity;

use App\Core\Security\Repository\TelemetryLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only request telemetry row written after the HTTP response is sent.
 */
#[ORM\Entity(repositoryClass: TelemetryLogRepository::class)]
#[ORM\Table(name: 'cp_system_telemetry_logs')]
#[ORM\Index(columns: ['severity', 'created_at'], name: 'idx_telemetry_severity_created')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_telemetry_ip')]
class SystemTelemetryLog
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_THREAT = 'threat';

    public const EVENT_PAGE_VIEW = 'page_view';
    public const EVENT_LOGIN_ATTEMPT = 'login_attempt';
    public const EVENT_SCANNER_DETECTED = 'scanner_detected';
    public const EVENT_SQLI_ATTEMPT = 'sqli_attempt';
    public const EVENT_XSS_ATTEMPT = 'xss_attempt';
    public const EVENT_PATH_TRAVERSAL = 'path_traversal';
    public const EVENT_CSP_VIOLATION = 'csp_violation';
    public const EVENT_REQUEST_BLOCKED = 'request_blocked';
    public const EVENT_LOGIN_LOCKOUT = 'login_lockout';
    public const EVENT_IP_AUTOBAN = 'ip_autoban';
    public const EVENT_FLOOD_BLOCKED = 'flood_blocked';
    public const EVENT_TWOFACTOR_FAILED = 'twofactor_failed';
    public const EVENT_SESSION_REVOKED = 'session_revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45)]
    private string $ipAddress;

    #[ORM\Column(name: 'user_id', type: 'bigint', nullable: true)]
    private ?string $userId;

    #[ORM\Column(name: 'request_method', type: 'string', length: 10)]
    private string $requestMethod;

    #[ORM\Column(name: 'request_uri', type: 'string', length: 1000)]
    private string $requestUri;

    #[ORM\Column(name: 'user_agent', type: 'string', length: 500)]
    private string $userAgent;

    #[ORM\Column(type: 'string', length: 16)]
    private string $severity;

    #[ORM\Column(name: 'event_type', type: 'string', length: 32)]
    private string $eventType;

    #[ORM\Column(name: 'threat_score', type: 'integer')]
    private int $threatScore;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $details;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $ipAddress,
        ?int $userId,
        string $requestMethod,
        string $requestUri,
        string $userAgent,
        string $severity,
        string $eventType,
        int $threatScore,
        array $details,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->ipAddress = $ipAddress;
        $this->userId = $userId === null ? null : (string) $userId;
        $this->requestMethod = $requestMethod;
        $this->requestUri = $requestUri;
        $this->userAgent = $userAgent;
        $this->severity = $severity;
        $this->eventType = $eventType;
        $this->threatScore = max(0, min(100, $threatScore));
        $this->details = $details;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id === null ? null : (int) $this->id;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getUserId(): ?int
    {
        return $this->userId === null ? null : (int) $this->userId;
    }

    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    public function getRequestUri(): string
    {
        return $this->requestUri;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getThreatScore(): int
    {
        return $this->threatScore;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
