<?php

declare(strict_types=1);

namespace App\Core\Notification;

use App\Core\Notification\Entity\Notification;
use App\Core\Notification\Repository\NotificationRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for user-facing notifications.
 *
 * In-app rows are written in the request thread (badge must update immediately).
 * Mail is always enqueued — never SMTP here.
 */
final class NotificationDispatcher
{
    /**
     * @param iterable<NotificationChannelInterface> $channels
     */
    public function __construct(
        private readonly NotificationTypeRegistry $types,
        private readonly NotificationPreferenceResolver $preferences,
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly iterable $channels,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $eventKey,
        User $recipient,
        array $payload,
        ?User $actor = null,
        ?NotificationSubject $subject = null,
        ?string $dedupeKey = null,
        ?string $tenantId = null,
    ): ?Notification {
        $type = $this->types->get($eventKey);
        if ($type === null) {
            $this->logger->warning('Unknown notification event key.', ['event' => $eventKey]);

            return null;
        }

        if ($recipient->getStatus() === User::STATUS_BANNED) {
            return null;
        }

        if ($actor !== null && $actor->getId() === $recipient->getId() && !$type->allowSelf) {
            return null;
        }

        $wanted = $this->preferences->channelsFor($recipient, $type);
        if ($wanted === []) {
            return null;
        }

        $notification = null;
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $existing = $this->notifications->findByDedupe($recipient, $eventKey, $dedupeKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        if (\in_array('in_app', $wanted, true) || $this->needsNotificationRow($wanted)) {
            $notification = new Notification(
                $recipient,
                $eventKey,
                $payload,
                $actor,
                $subject?->type,
                $subject?->id,
                $dedupeKey,
                $tenantId,
            );
            $this->entityManager->persist($notification);
            $this->entityManager->flush();
        }

        $context = new NotificationDeliveryContext(
            $type,
            $recipient,
            $payload,
            $actor,
            $subject,
            $notification,
            $tenantId,
        );

        foreach ($this->channels as $channel) {
            if (!\in_array($channel->getKey(), $wanted, true)) {
                continue;
            }
            if (!$channel->supports($eventKey)) {
                continue;
            }
            try {
                $channel->deliver($context);
            } catch (\Throwable $e) {
                $this->logger->error('Notification channel failed.', [
                    'channel' => $channel->getKey(),
                    'event' => $eventKey,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    /**
     * @param list<string> $wanted
     */
    private function needsNotificationRow(array $wanted): bool
    {
        foreach ($wanted as $channel) {
            if ($channel === 'mail_instant' || $channel === 'mail_digest') {
                return true;
            }
        }

        return false;
    }
}
