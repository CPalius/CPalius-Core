<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Modules\Forum\Repository\ForumNotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Forum notifications (MegaforBB notifications equivalent).
 * Reply, quote, like, and reputation events share one table.
 */
#[ORM\Entity(repositoryClass: ForumNotificationRepository::class)]
#[ORM\Table(name: 'forum_notifications')]
#[ORM\Index(columns: ['user_id', 'read_at'], name: 'idx_forum_notif_user_unread')]
#[ORM\Index(columns: ['content_type', 'content_id'], name: 'idx_forum_notif_content')]
#[ORM\Index(columns: ['created_at'], name: 'idx_forum_notif_created')]
class ForumNotification
{
    public const TYPE_REPLY = 'reply';
    public const TYPE_THREAD_REPLY = 'thread_reply';
    public const TYPE_QUOTE = 'quote';
    public const TYPE_REACTION = 'reaction';
    public const TYPE_DISLIKE = 'dislike';
    public const TYPE_MENTION = 'mention';
    public const TYPE_REPUTATION = 'reputation';

    public const CONTENT_POST = 'post';
    public const CONTENT_TOPIC = 'topic';
    public const CONTENT_USER = 'user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $sender = null;

    #[ORM\Column(name: 'sender_name', type: 'string', length: 180, nullable: true)]
    private ?string $senderName = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $type;

    #[ORM\Column(name: 'content_type', type: 'string', length: 32)]
    private string $contentType;

    #[ORM\Column(name: 'content_id', type: 'integer', nullable: true)]
    private ?int $contentId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        User $user,
        string $type,
        string $contentType,
        ?int $contentId = null,
        array $data = [],
        ?User $sender = null,
        ?string $senderName = null,
    ) {
        $this->user = $user;
        $this->type = $type;
        $this->contentType = $contentType;
        $this->contentId = $contentId;
        $this->data = $data;
        $this->sender = $sender;
        $this->senderName = $senderName;
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

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getContentId(): ?int
    {
        return $this->contentId;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
