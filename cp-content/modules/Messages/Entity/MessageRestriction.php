<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageRestrictionRepository;

/**
 * Moderator-imposed messaging mute. One active row per user.
 */
#[ORM\Entity(repositoryClass: MessageRestrictionRepository::class)]
#[ORM\Table(name: 'cp_message_restrictions')]
#[ORM\UniqueConstraint(name: 'uniq_message_restriction_user', columns: ['user_id'])]
#[ORM\Index(columns: ['expires_at'], name: 'idx_message_restriction_expires')]
class MessageRestriction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 500)]
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

    public function __construct(User $user, string $reason, ?User $createdBy, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->user = $user;
        $reason = trim(strip_tags($reason));
        $this->reason = $reason !== '' ? mb_substr($reason, 0, 500) : 'restricted';
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

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function replace(string $reason, ?User $createdBy, ?\DateTimeImmutable $expiresAt): void
    {
        $reason = trim(strip_tags($reason));
        $this->reason = $reason !== '' ? mb_substr($reason, 0, 500) : $this->reason;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->revokedAt = null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        $now ??= new \DateTimeImmutable();

        return $this->expiresAt === null || $this->expiresAt > $now;
    }
}
