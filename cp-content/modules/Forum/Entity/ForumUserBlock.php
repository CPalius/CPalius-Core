<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumUserBlockRepository;

/**
 * Ignore list (cp_forum_user_blocks).
 * user_id is the blocker; blocked_id is the member they will not see.
 */
#[ORM\Entity(repositoryClass: ForumUserBlockRepository::class)]
#[ORM\Table(name: 'cp_forum_user_blocks')]
#[ORM\UniqueConstraint(name: 'uniq_forum_user_block', columns: ['user_id', 'blocked_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_user_block_user')]
#[ORM\Index(columns: ['blocked_id'], name: 'idx_forum_user_block_blocked')]
class ForumUserBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocked_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, User $blocked)
    {
        if ($user->getId() !== null && $user->getId() === $blocked->getId()) {
            throw new \InvalidArgumentException('A member cannot block themselves.');
        }

        $this->user = $user;
        $this->blocked = $blocked;
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

    public function getBlocked(): User
    {
        return $this->blocked;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
