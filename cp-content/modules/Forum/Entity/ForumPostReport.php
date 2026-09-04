<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Modules\Forum\Repository\ForumPostReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Flagged-post report feeding the AACP moderation queue.
 */
#[ORM\Entity(repositoryClass: ForumPostReportRepository::class)]
#[ORM\Table(name: 'forum_post_reports')]
#[ORM\Index(columns: ['status'], name: 'idx_forum_post_report_status')]
class ForumPostReport
{
    public const STATUS_OPEN = 0;
    public const STATUS_RESOLVED = 1;
    public const STATUS_DISMISSED = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPost $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reporter = null;

    #[ORM\Column(name: 'reporter_name', type: 'string', length: 100, nullable: true)]
    private ?string $reporterName = null;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(type: 'smallint')]
    private int $status = self::STATUS_OPEN;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(ForumPost $post, string $reason, ?User $reporter, ?string $reporterName)
    {
        $this->post = $post;
        $this->reason = $reason;
        $this->reporter = $reporter;
        $this->reporterName = $reporterName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function getReporterName(): ?string
    {
        return $this->reporterName;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function resolve(User $moderator): static
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedBy = $moderator;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function dismiss(User $moderator): static
    {
        $this->status = self::STATUS_DISMISSED;
        $this->resolvedBy = $moderator;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
