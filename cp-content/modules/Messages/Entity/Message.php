<?php

declare(strict_types=1);

namespace Modules\Messages\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Messages\Repository\MessageRepository;

/**
 * One body inside a thread. Soft-deleted rows stay so reports and audit remain.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'cp_messages')]
#[ORM\Index(columns: ['thread_id', 'created_at'], name: 'idx_message_thread_created')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MessageThread::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'thread_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MessageThread $thread;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'body_format', type: 'string', length: 32)]
    private string $bodyFormat;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    public function __construct(MessageThread $thread, User $author, string $body, string $bodyFormat)
    {
        $this->thread = $thread;
        $this->author = $author;
        $this->body = $body;
        $this->bodyFormat = $bodyFormat;
        $this->createdAt = new \DateTimeImmutable();
        $thread->addMessage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): MessageThread
    {
        return $this->thread;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getBodyFormat(): string
    {
        return $this->bodyFormat;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function replaceBody(string $body, string $bodyFormat): void
    {
        $this->body = $body;
        $this->bodyFormat = $bodyFormat;
        $this->editedAt = new \DateTimeImmutable();
    }

    public function softDelete(User $actor): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $actor;
    }
}
