<?php

declare(strict_types=1);

namespace App\Core\Notification\Entity;

use App\Core\Notification\Repository\NotificationRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Core in-app notification row. Modules declare event keys; they do not own
 * separate inbox tables long-term (Forum's table is a T3.1 leftover).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'cp_notifications')]
#[ORM\UniqueConstraint(name: 'uniq_notif_dedupe', columns: ['user_id', 'event_key', 'dedupe_key'])]
#[ORM\Index(columns: ['user_id', 'read_at'], name: 'idx_notif_user_unread')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_notif_user_created')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'idx_notif_subject')]
#[ORM\Index(columns: ['event_key'], name: 'idx_notif_event')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'event_key', type: 'string', length: 128)]
    private string $eventKey;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(name: 'actor_name', type: 'string', length: 180, nullable: true)]
    private ?string $actorName = null;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 32, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(name: 'subject_id', type: 'integer', nullable: true)]
    private ?int $subjectId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(name: 'dedupe_key', type: 'string', length: 191, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 64, nullable: true)]
    private ?string $tenantId = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        User $user,
        string $eventKey,
        array $data = [],
        ?User $actor = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $dedupeKey = null,
        ?string $tenantId = null,
    ) {
        $this->user = $user;
        $this->eventKey = $eventKey;
        $this->data = $data;
        $this->actor = $actor;
        $this->actorName = $actor?->getPublicName();
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->dedupeKey = $dedupeKey;
        $this->tenantId = $tenantId;
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

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getDedupeKey(): ?string
    {
        return $this->dedupeKey;
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

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}
