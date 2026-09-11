<?php

declare(strict_types=1);

namespace App\Core\Notification\Channel;

use App\Core\Notification\Message\SendNotificationMailMessage;
use App\Core\Notification\NotificationChannelInterface;
use App\Core\Notification\NotificationDeliveryContext;
use Symfony\Component\Messenger\MessageBusInterface;

final class MailInstantChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function getKey(): string
    {
        return 'mail_instant';
    }

    public function supports(string $eventKey): bool
    {
        return true;
    }

    public function deliver(NotificationDeliveryContext $context): void
    {
        $notification = $context->notification;
        if ($notification === null || $notification->getId() === null) {
            return;
        }

        $email = trim($context->recipient->getEmail());
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $this->messageBus->dispatch(new SendNotificationMailMessage(
            notificationId: $notification->getId(),
            userId: (int) $context->recipient->getId(),
            eventKey: $context->type->eventKey,
            to: $email,
            locale: null,
            payload: $context->payload,
            mailTemplate: $context->type->mailTemplate,
        ));
    }
}
