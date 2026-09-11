<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Modules\Forum\Repository\ForumPresenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who-is-online presence for forum visitors (members and guests).
 */
#[ORM\Entity(repositoryClass: ForumPresenceRepository::class)]
#[ORM\Table(name: 'forum_presence')]
#[ORM\UniqueConstraint(name: 'uniq_forum_presence_session', columns: ['session_hash'])]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_forum_presence_seen')]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_presence_user')]
#[ORM\Index(columns: ['topic_id'], name: 'idx_forum_presence_topic')]
class ForumPresence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'session_hash', type: 'string', length: 64)]
    private string $sessionHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ForumTopic $topic = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(string $sessionHash, ?User $user = null)
    {
        $this->sessionHash = $sessionHash;
        $this->user = $user;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionHash(): string
    {
        return $this->sessionHash;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getTopic(): ?ForumTopic
    {
        return $this->topic;
    }

    public function setTopic(?ForumTopic $topic): void
    {
        $this->topic = $topic;
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
