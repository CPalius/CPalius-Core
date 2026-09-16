<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageThreadRepository;

/**
 * One 1:1 conversation. pair_key is the uniqueness gate so two members cannot
 * open a second thread about the same context.
 */
#[ORM\Entity(repositoryClass: MessageThreadRepository::class)]
#[ORM\Table(name: 'cp_message_threads')]
#[ORM\UniqueConstraint(name: 'uniq_message_thread_public', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_message_thread_pair', columns: ['pair_key'])]
#[ORM\Index(columns: ['last_message_at'], name: 'idx_message_thread_last')]
#[ORM\Index(columns: ['context_type', 'context_id'], name: 'idx_message_thread_context')]
class MessageThread
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: 'string', length: 16)]
    private string $publicId;

    #[ORM\Column(name: 'pair_key', type: 'string', length: 96)]
    private string $pairKey;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(name: 'context_type', type: 'string', length: 64, nullable: true)]
    private ?string $contextType = null;

    #[ORM\Column(name: 'context_id', type: 'integer', nullable: true)]
    private ?int $contextId = null;

    #[ORM\Column(name: 'context_label', type: 'string', length: 191, nullable: true)]
    private ?string $contextLabel = null;

    #[ORM\Column(name: 'context_url', type: 'string', length: 500, nullable: true)]
    private ?string $contextUrl = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'last_message_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastMessageAt;

    #[ORM\Column(name: 'message_count', type: 'integer')]
    private int $messageCount = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $closed = false;

    #[ORM\Column(name: 'closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, MessageParticipant> */
    #[ORM\OneToMany(targetEntity: MessageParticipant::class, mappedBy: 'thread', cascade: ['persist'])]
    private Collection $participants;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'thread', cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $pairKey, User $createdBy, ?string $subject = null)
    {
        $this->publicId = bin2hex(random_bytes(8));
        $this->pairKey = $pairKey;
        $this->createdBy = $createdBy;
        $this->subject = self::normalizeSubject($subject);
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->lastMessageAt = $now;
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public static function pairKey(int $userA, int $userB, ?string $contextType, ?int $contextId): string
    {
        $low = min($userA, $userB);
        $high = max($userA, $userB);
        $type = ($contextType !== null && $contextType !== '') ? $contextType : '-';
        $id = $contextId !== null && $contextId > 0 ? (string) $contextId : '-';

        return $low.':'.$high.':'.$type.':'.$id;
    }

    public static function normalizeSubject(?string $subject): ?string
    {
        $trimmed = trim(strip_tags((string) $subject));

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 191);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getPairKey(): string
    {
        return $this->pairKey;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): void
    {
        $this->subject = self::normalizeSubject($subject);
    }

    public function getContextType(): ?string
    {
        return $this->contextType;
    }

    public function getContextId(): ?int
    {
        return $this->contextId;
    }

    public function getContextLabel(): ?string
    {
        return $this->contextLabel;
    }

    public function getContextUrl(): ?string
    {
        return $this->contextUrl;
    }

    public function setContext(?string $type, ?int $id, ?string $label, ?string $url): void
    {
        $this->contextType = $type !== null && $type !== '' ? mb_substr($type, 0, 64) : null;
        $this->contextId = $id !== null && $id > 0 ? $id : null;
        $label = trim(strip_tags((string) $label));
        $this->contextLabel = $label !== '' ? mb_substr($label, 0, 191) : null;
        $this->contextUrl = $url;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MessageParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addParticipant(MessageParticipant $participant): void
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }
    }

    public function addMessage(Message $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }

        $this->lastMessageAt = $message->getCreatedAt();
        ++$this->messageCount;
    }

    public function participantFor(User $user): ?MessageParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUser()->getId() === $user->getId()) {
                return $participant;
            }
        }

        return null;
    }

    public function otherParticipant(User $viewer): ?User
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUser()->getId() !== $viewer->getId()) {
                return $participant->getUser();
            }
        }

        return null;
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participantFor($user) instanceof MessageParticipant;
    }

    public function close(User $moderator): void
    {
        $this->closed = true;
        $this->closedAt = new \DateTimeImmutable();
        $this->closedBy = $moderator;
    }

    public function reopen(): void
    {
        $this->closed = false;
        $this->closedAt = null;
        $this->closedBy = null;
    }
}
