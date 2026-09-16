<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumBanRepository;

/**
 * Forum-only ban/mute; does not affect the site-wide account.
 * BAN blocks forum routes; MUTE allows read but blocks topic/reply/like.
 */
#[ORM\Entity(repositoryClass: ForumBanRepository::class)]
#[ORM\Table(name: 'cp_forum_bans')]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_ban_user')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_forum_ban_ip')]
#[ORM\Index(columns: ['email'], name: 'idx_forum_ban_email')]
class ForumBan
{
    public const TYPE_BAN = 0;
    public const TYPE_MUTE = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 64, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

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

    public function __construct(?User $user, int $type, string $reason, ?User $createdBy, ?\DateTimeImmutable $expiresAt, ?string $ipAddress = null, ?string $email = null)
    {
        $this->user = $user;
        $this->type = $type;
        $this->reason = $reason;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->ipAddress = $ipAddress !== null && $ipAddress !== '' ? $ipAddress : null;
        $this->email = $email !== null && $email !== '' ? mb_strtolower($email) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getEmail(): ?string
    {
        return $this->email;
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
