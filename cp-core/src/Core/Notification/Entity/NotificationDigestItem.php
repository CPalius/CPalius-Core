<?php

declare(strict_types=1);

namespace App\Core\Notification\Entity;

use App\Core\Notification\Repository\NotificationDigestItemRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Deferred mail delivery bucket. In-app notifications stay immediate; only
 * mail is batched when the user chooses daily/weekly digest mode.
 */
#[ORM\Entity(repositoryClass: NotificationDigestItemRepository::class)]
#[ORM\Table(name: 'cp_notification_digest_queue')]
#[ORM\UniqueConstraint(name: 'uniq_digest_notif', columns: ['user_id', 'notification_id'])]
#[ORM\Index(columns: ['bucket', 'sent_at'], name: 'idx_digest_bucket_sent')]
class NotificationDigestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;

    #[ORM\Column(type: 'string', length: 32)]
    private string $bucket;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(User $user, Notification $notification, string $bucket)
    {
        $this->user = $user;
        $this->notification = $notification;
        $this->bucket = $bucket;
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

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(): void
    {
        $this->sentAt = new \DateTimeImmutable();
    }
}
