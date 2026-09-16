<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumTopicUserStateRepository;

/**
 * What one member has going on with one thread: when they last opened it, and
 * whether they are watching it.
 *
 * Replaces ForumTopicView and ForumTopicWatch, which were the same row twice —
 * both keyed UNIQUE(topic_id, user_id), both hanging off the same two entities,
 * differing only in what their single timestamp column meant. A member who reads
 * and watches a thread needed two rows in two tables to say one thing.
 *
 * Both timestamps are nullable and carry the fact on their own: lastSeenAt null
 * means never opened, watchingSince null means not watching. Unwatching clears
 * the column rather than deleting the row, so the read position survives it.
 */
#[ORM\Entity(repositoryClass: ForumTopicUserStateRepository::class)]
#[ORM\Table(name: 'cp_forum_topic_user_state')]
#[ORM\UniqueConstraint(name: 'uniq_cp_forum_topic_user_state', columns: ['topic_id', 'user_id'])]
#[ORM\Index(columns: ['topic_id'], name: 'idx_cp_forum_tus_topic')]
#[ORM\Index(columns: ['user_id'], name: 'idx_cp_forum_tus_user')]
class ForumTopicUserState
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

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'watching_since', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $watchingSince = null;

    public function __construct(ForumTopic $topic, User $user)
    {
        $this->topic = $topic;
        $this->user = $user;
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

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function hasSeen(): bool
    {
        return $this->lastSeenAt !== null;
    }

    public function touch(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function isWatching(): bool
    {
        return $this->watchingSince !== null;
    }

    public function getWatchingSince(): ?\DateTimeImmutable
    {
        return $this->watchingSince;
    }

    public function startWatching(): void
    {
        $this->watchingSince ??= new \DateTimeImmutable();
    }

    public function stopWatching(): void
    {
        $this->watchingSince = null;
    }

    /**
     * True when the row no longer says anything and can be deleted.
     */
    public function isEmpty(): bool
    {
        return $this->lastSeenAt === null && $this->watchingSince === null;
    }
}
