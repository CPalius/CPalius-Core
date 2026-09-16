<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageBlockRepository;

/**
 * Member-to-member block. Either direction refuses a new message.
 */
#[ORM\Entity(repositoryClass: MessageBlockRepository::class)]
#[ORM\Table(name: 'cp_message_blocks')]
#[ORM\UniqueConstraint(name: 'uniq_message_block', columns: ['blocker_id', 'blocked_id'])]
class MessageBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocker_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $blocker;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocked_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $blocker, User $blocked, ?string $reason = null)
    {
        $this->blocker = $blocker;
        $this->blocked = $blocked;
        $reason = trim(strip_tags((string) $reason));
        $this->reason = $reason !== '' ? mb_substr($reason, 0, 191) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlocker(): User
    {
        return $this->blocker;
    }

    public function getBlocked(): User
    {
        return $this->blocked;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
