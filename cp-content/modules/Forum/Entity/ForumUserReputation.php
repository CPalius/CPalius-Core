<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumUserReputationRepository;

/**
 * Peer reputation ledger (MegaforBB user_reputations equivalent).
 * Optional reason plus linked topic/post.
 */
#[ORM\Entity(repositoryClass: ForumUserReputationRepository::class)]
#[ORM\Table(name: 'forum_user_reputations')]
#[ORM\Index(columns: ['to_user_id', 'created_at'], name: 'idx_forum_rep_to')]
#[ORM\Index(columns: ['from_user_id'], name: 'idx_forum_rep_from')]
#[ORM\Index(columns: ['topic_id'], name: 'idx_forum_rep_topic')]
class ForumUserReputation
{
    public const VALUE_POSITIVE = 1;
    public const VALUE_NEGATIVE = -1;

    public const REASON_HELPFUL = 'helpful';
    public const REASON_INFORMATIVE = 'informative';
    public const REASON_EXPERT = 'expert';
    public const REASON_FRIENDLY = 'friendly';
    public const REASON_QUALITY = 'quality';
    public const REASON_OTHER = 'other';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_HELPFUL,
        self::REASON_INFORMATIVE,
        self::REASON_EXPERT,
        self::REASON_FRIENDLY,
        self::REASON_QUALITY,
        self::REASON_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'from_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $fromUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'to_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $toUser;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ForumTopic $topic = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ForumPost $post = null;

    /**
     * Free-text link to whatever the rep is about.
     *
     * Kept alongside $topic rather than instead of it: when the URL points at a
     * topic on this forum the relation is filled too, so the ledger keeps
     * working when that topic is renamed or moved. The string is what survives
     * when it is not — a link to another site, or to a page the giver could
     * reach and this code cannot resolve.
     */
    #[ORM\Column(name: 'topic_url', type: 'string', length: 500, nullable: true)]
    private ?string $topicUrl = null;

    #[ORM\Column(type: 'smallint')]
    private int $value;

    #[ORM\Column(type: 'string', length: 32)]
    private string $reason;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $fromUser,
        User $toUser,
        int $value,
        string $reason,
        ?ForumTopic $topic = null,
        ?ForumPost $post = null,
        ?string $comment = null,
        ?string $topicUrl = null,
    ) {
        $this->fromUser = $fromUser;
        $this->toUser = $toUser;
        $this->value = $value === self::VALUE_NEGATIVE ? self::VALUE_NEGATIVE : self::VALUE_POSITIVE;
        $this->reason = $reason;
        $this->topic = $topic;
        $this->post = $post;
        $this->comment = $comment !== null && $comment !== '' ? mb_substr($comment, 0, 500) : null;
        $this->topicUrl = $topicUrl !== null && trim($topicUrl) !== '' ? mb_substr(trim($topicUrl), 0, 500) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromUser(): User
    {
        return $this->fromUser;
    }

    public function getToUser(): User
    {
        return $this->toUser;
    }

    public function getTopic(): ?ForumTopic
    {
        return $this->topic;
    }

    public function getPost(): ?ForumPost
    {
        return $this->post;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getTopicUrl(): ?string
    {
        return $this->topicUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPositive(): bool
    {
        return $this->value === self::VALUE_POSITIVE;
    }
}
