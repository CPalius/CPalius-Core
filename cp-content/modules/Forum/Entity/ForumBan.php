<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Modules\Forum\Repository\ForumBanRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Forum-only ban/mute; does not affect the site-wide account.
 * BAN blocks forum routes; MUTE allows read but blocks topic/reply/like.
 */
#[ORM\Entity(repositoryClass: ForumBanRepository::class)]
#[ORM\Table(name: 'forum_bans')]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_ban_user')]
class ForumBan
{
    public const TYPE_BAN = 0;
    public const TYPE_MUTE = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'smallint')]
    private int $type = self::TYPE_MUTE;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, int $type, string $reason, ?User $createdBy, ?\DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->type = $type;
        $this->reason = $reason;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
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

    public function getType(): int
    {
        return $this->type;
    }

    public function isBan(): bool
    {
        return $this->type === self::TYPE_BAN;
    }

    public function isMute(): bool
    {
        return $this->type === self::TYPE_MUTE;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt <= new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }
}
