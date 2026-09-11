<?php

declare(strict_types=1);

namespace App\Core\Notification\Channel;

use App\Core\Notification\Entity\NotificationDigestItem;
use App\Core\Notification\NotificationChannelInterface;
use App\Core\Notification\NotificationDeliveryContext;
use App\Core\Notification\NotificationPreferenceResolver;
use App\Core\Notification\Repository\NotificationDigestItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MailDigestChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationDigestItemRepository $digestRepository,
        private readonly NotificationPreferenceResolver $preferences,
    ) {
    }

    public function getKey(): string
    {
        return 'mail_digest';
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

        if ($this->digestRepository->existsFor($context->recipient, $notification->getId())) {
            return;
        }

        $item = new NotificationDigestItem(
            $context->recipient,
            $notification,
            $this->preferences->currentBucket($context->recipient),
        );
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
}
