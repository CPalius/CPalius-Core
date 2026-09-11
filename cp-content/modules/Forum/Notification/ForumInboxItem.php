<?php

declare(strict_types=1);

namespace Modules\Forum\Notification;

use App\Core\Notification\Entity\Notification;
use App\Entity\User;

/**
 * Twig-facing adapter: core Notification rows look like the old ForumNotification API
 * (type / senderName / read) so theme templates stay stable during cutover.
 */
final class ForumInboxItem
{
    public function __construct(
        private readonly Notification $notification,
    ) {
    }

    public function getId(): ?int
    {
        return $this->notification->getId();
    }

    public function getUser(): User
    {
        return $this->notification->getUser();
    }

    public function getType(): string
    {
        $key = $this->notification->getEventKey();

        return str_starts_with($key, 'forum.') ? substr($key, 6) : $key;
    }

    public function getEventKey(): string
    {
        return $this->notification->getEventKey();
    }

    public function getSender(): ?User
    {
        return $this->notification->getActor();
    }

    public function getSenderName(): ?string
    {
        return $this->notification->getActorName();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->notification->getData();
    }

    public function isRead(): bool
    {
        return $this->notification->isRead();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->notification->getCreatedAt();
    }

    public function unwrap(): Notification
    {
        return $this->notification;
    }
}
