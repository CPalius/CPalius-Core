<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageParticipantRepository;

/**
 * Per-user state on a thread: unread, archive, mute, hide.
 */
#[ORM\Entity(repositoryClass: MessageParticipantRepository::class)]
#[ORM\Table(name: 'cp_message_participants')]
#[ORM\UniqueConstraint(name: 'uniq_message_participant', columns: ['thread_id', 'user_id'])]
#[ORM\Index(columns: ['user_id', 'hidden', 'archived', 'last_read_at'], name: 'idx_message_participant_inbox')]
class MessageParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MessageThread::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'thread_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MessageThread $thread;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'last_read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column(name: 'unread_count', type: 'integer')]
    private int $unreadCount = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $archived = false;

    #[ORM\Column(type: 'boolean')]
    private bool $muted = false;

    #[ORM\Column(type: 'boolean')]
    private bool $hidden = false;

    #[ORM\Column(name: 'joined_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct(MessageThread $thread, User $user)
    {
        $this->thread = $thread;
        $this->user = $user;
        $this->joinedAt = new \DateTimeImmutable();
        $thread->addParticipant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): MessageThread
    {
        return $this->thread;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function getUnreadCount(): int
    {
        return $this->unreadCount;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function incrementUnread(): void
    {
        ++$this->unreadCount;
        $this->hidden = false;
        $this->archived = false;
    }

    public function markRead(): void
    {
        $this->unreadCount = 0;
        $this->lastReadAt = new \DateTimeImmutable();
    }

    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
        if ($archived) {
            $this->unreadCount = 0;
        }
    }

    public function setMuted(bool $muted): void
    {
        $this->muted = $muted;
    }

    public function hide(): void
    {
        $this->hidden = true;
        $this->unreadCount = 0;
    }

    public function restore(): void
    {
        $this->hidden = false;
    }
}
