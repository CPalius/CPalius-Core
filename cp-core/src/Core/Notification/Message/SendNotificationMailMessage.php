<?php

declare(strict_types=1);

namespace App\Core\Notification\Message;

/**
 * Queued notification mail. Handler loads the notification and renders a template.
 */
final class SendNotificationMailMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $notificationId,
        public readonly int $userId,
        public readonly string $eventKey,
        public readonly string $to,
        public readonly ?string $locale,
        public readonly array $payload,
        public readonly string $mailTemplate,
    ) {
    }
}
