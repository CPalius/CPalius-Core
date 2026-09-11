<?php

declare(strict_types=1);

namespace App\Core\Queue\Entity;

use App\Core\Queue\Repository\AsyncJobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Isolated async work unit. HTTP request never waits on outbound I/O.
 */
#[ORM\Entity(repositoryClass: AsyncJobRepository::class)]
#[ORM\Table(name: 'cp_async_jobs')]
#[ORM\Index(columns: ['processed_at', 'available_at'], name: 'idx_async_jobs_due')]
#[ORM\Index(columns: ['type'], name: 'idx_async_jobs_type')]
class AsyncJob
{
    public const TYPE_OUTBOUND_WEBHOOK = 'outbound_webhook';
    public const TYPE_INBOUND_WEBHOOK = 'inbound_webhook';

    public const MAX_ATTEMPTS = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 64, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'failed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'last_error', type: 'string', length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $type, array $payload, ?string $tenantId = null, ?\DateTimeImmutable $availableAt = null)
    {
        $this->type = $type;
        $this->payload = $payload;
        $this->tenantId = $tenantId;
        $this->availableAt = $availableAt ?? new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getAvailableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function markRetry(string $error, \DateTimeImmutable $nextAttempt): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 500);
        $this->availableAt = $nextAttempt;
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->failedAt = new \DateTimeImmutable();
            $this->processedAt = new \DateTimeImmutable();
        }
    }

    public function markDone(): void
    {
        $this->processedAt = new \DateTimeImmutable();
        $this->lastError = null;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function isTerminal(): bool
    {
        return $this->processedAt !== null;
    }
}
