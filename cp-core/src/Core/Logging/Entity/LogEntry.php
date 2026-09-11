<?php

declare(strict_types=1);

namespace App\Core\Logging\Entity;

use App\Core\Logging\Repository\LogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Application watchdog row (Monolog → DB). Not entity-audit and not security telemetry.
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class)]
#[ORM\Table(name: 'cp_log_entries')]
#[ORM\Index(columns: ['level', 'created_at'], name: 'idx_log_level_created')]
#[ORM\Index(columns: ['channel', 'created_at'], name: 'idx_log_channel_created')]
class LogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $level;

    #[ORM\Column(type: 'string', length: 64)]
    private string $channel;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $extra;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $level,
        string $channel,
        string $message,
        array $context = [],
        array $extra = [],
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->level = $level;
        $this->channel = $channel;
        $this->message = $message;
        $this->context = $context;
        $this->extra = $extra;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
