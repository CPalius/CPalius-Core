<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumWarningRepository;

/**
 * Staff warning against a member (cp_forum_warnings).
 * Points are denormalized onto ForumUserStats.warning_points at write time.
 */
#[ORM\Entity(repositoryClass: ForumWarningRepository::class)]
#[ORM\Table(name: 'cp_forum_warnings')]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_warning_user')]
#[ORM\Index(columns: ['warned_by_id'], name: 'idx_forum_warning_by')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_forum_warning_expires')]
class ForumWarning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'warned_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $warnedBy = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $points = 1;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $reason, int $points = 1, ?User $warnedBy = null)
    {
        $this->user = $user;
        $this->reason = $reason;
        $this->points = max(0, $points);
        $this->warnedBy = $warnedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWarnedBy(): ?User
    {
        return $this->warnedBy;
    }

    public function setWarnedBy(?User $warnedBy): static
    {
        $this->warnedBy = $warnedBy;

        return $this;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = max(0, $points);

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
