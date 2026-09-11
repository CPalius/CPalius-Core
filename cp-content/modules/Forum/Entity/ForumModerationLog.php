<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumModerationLogRepository;

#[ORM\Entity(repositoryClass: ForumModerationLogRepository::class)]
#[ORM\Table(name: 'forum_moderation_logs')]
#[ORM\Index(columns: ['created_at'], name: 'idx_forum_modlog_created')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_forum_modlog_target')]
class ForumModerationLog
{
    public const TARGET_TOPIC = 'topic';
    public const TARGET_POST = 'post';
    public const TARGET_USER = 'user';
    public const TARGET_POLL = 'poll';
    public const TARGET_BAN = 'ban';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $action;

    #[ORM\Column(name: 'target_type', type: 'string', length: 16)]
    private string $targetType;

    #[ORM\Column(name: 'target_id', type: 'integer')]
    private int $targetId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $action, string $targetType, int $targetId, ?User $actor = null, array $details = [])
    {
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->actor = $actor;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
