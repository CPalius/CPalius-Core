<?php

namespace App\Entity;

use App\Repository\PerformanceBackendStatusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Runtime status for performance backends (redis, varnish, etc.), separate from Setting.
 * isEnabled changes only via PerformanceBackendRegistry after a successful test.
 */
#[ORM\Entity(repositoryClass: PerformanceBackendStatusRepository::class)]
#[ORM\Table(name: 'cp_performance_backend_status')]
#[ORM\UniqueConstraint(name: 'uniq_performance_backend_id', columns: ['backend_id'])]
class PerformanceBackendStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'backend_id', type: 'string', length: 32)]
    private string $backendId;

    #[ORM\Column(name: 'is_enabled', type: 'boolean')]
    private bool $isEnabled = false;

    #[ORM\Column(name: 'last_test_success', type: 'boolean')]
    private bool $lastTestSuccess = false;

    #[ORM\Column(name: 'last_test_status', type: 'string', length: 32, nullable: true)]
    private ?string $lastTestStatus = null;

    /**
     * Translation key for last test message, not stored localized text.
     */
    #[ORM\Column(name: 'last_test_message_key', type: 'string', length: 191, nullable: true)]
    private ?string $lastTestMessageKey = null;

    /**
     * @var array<string, string|int|float>
     */
    #[ORM\Column(name: 'last_test_message_params', type: 'json')]
    private array $lastTestMessageParams = [];

    #[ORM\Column(name: 'last_tested_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastTestedAt = null;

    public function __construct(string $backendId)
    {
        $this->backendId = $backendId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBackendId(): string
    {
        return $this->backendId;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isLastTestSuccess(): bool
    {
        return $this->lastTestSuccess;
    }

    public function getLastTestStatus(): ?string
    {
        return $this->lastTestStatus;
    }

    public function getLastTestMessageKey(): ?string
    {
        return $this->lastTestMessageKey;
    }

    /**
     * @return array<string, string|int|float>
     */
    public function getLastTestMessageParams(): array
    {
        return $this->lastTestMessageParams;
    }

    public function getLastTestedAt(): ?\DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    /**
     * Records a connection test; failed tests force isEnabled false.
     *
     * @param array<string, string|int|float> $messageParams
     */
    public function recordTestResult(bool $success, string $status, string $messageKey, array $messageParams = []): static
    {
        $this->lastTestSuccess = $success;
        $this->lastTestStatus = $status;
        $this->lastTestMessageKey = $messageKey;
        $this->lastTestMessageParams = $messageParams;
        $this->lastTestedAt = new \DateTimeImmutable();

        if (!$success) {
            $this->isEnabled = false;
        }

        return $this;
    }
}
