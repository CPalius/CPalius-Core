<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumTopicViewRepository;

/**
 * Logged-in member who opened a thread (unique per topic + user).
 */
#[ORM\Entity(repositoryClass: ForumTopicViewRepository::class)]
#[ORM\Table(name: 'forum_topic_views')]
#[ORM\UniqueConstraint(name: 'uniq_forum_topic_view_topic_user', columns: ['topic_id', 'user_id'])]
#[ORM\Index(columns: ['topic_id'], name: 'idx_forum_topic_view_topic')]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_topic_view_user')]
class ForumTopicView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumTopic $topic;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(ForumTopic $topic, User $user)
    {
        $this->topic = $topic;
        $this->user = $user;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
