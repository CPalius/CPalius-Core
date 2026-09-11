<?php

declare(strict_types=1);

namespace App\Core\Notification\Channel;

use App\Core\Notification\NotificationChannelInterface;
use App\Core\Notification\NotificationDeliveryContext;

/**
 * In-app channel is a no-op deliver: the dispatcher already persisted the row
 * so the badge count is visible before the request ends. Kept as a channel so
 * preference resolution stays uniform.
 */
final class InAppChannel implements NotificationChannelInterface
{
    public function getKey(): string
    {
        return 'in_app';
    }

    public function supports(string $eventKey): bool
    {
        return true;
    }

    public function deliver(NotificationDeliveryContext $context): void
    {
        // Persistence happens in NotificationDispatcher before channel fan-out.
    }
}
