<?php

declare(strict_types=1);

namespace App\Core\Notification;

/**
 * Outbound notification channel. Tagged via services.yaml _instanceof
 * (`cpalius.notification.channel`) — do not also AutoconfigureTag here or
 * deliveries would fire twice.
 */
interface NotificationChannelInterface
{
    public function getKey(): string;

    public function supports(string $eventKey): bool;

    public function deliver(NotificationDeliveryContext $context): void;
}
