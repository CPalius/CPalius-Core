<?php

declare(strict_types=1);

namespace App\Core\Notification;

/**
 * Polymorphic subject of a notification (node, forum post, user, …).
 */
final readonly class NotificationSubject
{
    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {
    }
}
