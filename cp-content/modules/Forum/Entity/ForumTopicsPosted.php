<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumTopicsPostedRepository;

/**
 * Participation index (cp_forum_topics_posted).
 * One row per (user, topic) the member posted in — replaces GROUP BY author scans.
 */
#[ORM\Entity(repositoryClass: ForumTopicsPostedRepository::class)]
#[ORM\Table(name: 'cp_forum_topics_posted')]
#[ORM\UniqueConstraint(name: 'uniq_forum_topics_posted', columns: ['user_id', 'topic_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_topics_posted_user')]
#[ORM\Index(columns: ['topic_id'], name: 'idx_forum_topics_posted_topic')]
class ForumTopicsPosted
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumTopic $topic;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ForumTopic $topic)
    {
        $this->user = $user;
        $this->topic = $topic;
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

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
