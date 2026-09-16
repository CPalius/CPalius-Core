<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageReportRepository;

/**
 * A flag on one message. One reporter may flag a given message only once.
 */
#[ORM\Entity(repositoryClass: MessageReportRepository::class)]
#[ORM\Table(name: 'cp_message_reports')]
#[ORM\UniqueConstraint(name: 'uniq_message_report_once', columns: ['message_id', 'reporter_id'])]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_message_report_status')]
class MessageReport
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const REASON_SPAM = 'spam';
    public const REASON_HARASSMENT = 'harassment';
    public const REASON_SCAM = 'scam';
    public const REASON_INAPPROPRIATE = 'inappropriate';
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_SPAM,
        self::REASON_HARASSMENT,
        self::REASON_SCAM,
        self::REASON_INAPPROPRIATE,
        self::REASON_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reporter = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $reason;

    #[ORM\Column(type: 'string', length: 1000, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(name: 'resolution_note', type: 'string', length: 500, nullable: true)]
    private ?string $resolutionNote = null;

    public function __construct(Message $message, User $reporter, string $reason, ?string $details = null)
    {
        $this->message = $message;
        $this->reporter = $reporter;
        $this->reason = \in_array($reason, self::REASONS, true) ? $reason : self::REASON_OTHER;
        $details = trim(strip_tags((string) $details));
        $this->details = $details !== '' ? mb_substr($details, 0, 1000) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN || $this->status === self::STATUS_REVIEWING;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getResolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function markReviewing(): void
    {
        if ($this->isOpen()) {
            $this->status = self::STATUS_REVIEWING;
        }
    }

    public function resolve(User $moderator, string $note = ''): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedBy = $moderator;
        $this->resolvedAt = new \DateTimeImmutable();
        $note = trim(strip_tags($note));
        $this->resolutionNote = $note !== '' ? mb_substr($note, 0, 500) : null;
    }

    public function dismiss(User $moderator, string $note = ''): void
    {
        $this->status = self::STATUS_DISMISSED;
        $this->resolvedBy = $moderator;
        $this->resolvedAt = new \DateTimeImmutable();
        $note = trim(strip_tags($note));
        $this->resolutionNote = $note !== '' ? mb_substr($note, 0, 500) : null;
    }
}
