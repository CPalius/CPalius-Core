<?php

declare(strict_types=1);

namespace App\Core\Notification;

use App\Core\Notification\Entity\Notification;
use App\Entity\User;

/**
 * Immutable context handed to every channel handler.
 */
final readonly class NotificationDeliveryContext
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public NotificationTypeDefinition $type,
        public User $recipient,
        public array $payload,
        public ?User $actor,
        public ?NotificationSubject $subject,
        public ?Notification $notification,
        public ?string $tenantId,
    ) {
    }
}
