<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Core\Notification\NotificationDispatcher;
use App\Core\Notification\NotificationSubject;
use App\Entity\User;
use Modules\Messages\Entity\Message;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * New-message pings go through the core dispatcher. This module never talks SMTP.
 */
final class MessagesNotificationService
{
    public const EVENT_NEW = 'messages.new';

    public function __construct(
        private readonly MessagesConfig $config,
        private readonly NotificationDispatcher $dispatcher,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function notifyNewMessage(Message $message, User $recipient): void
    {
        if (!$this->config->notificationsEnabled()) {
            return;
        }

        $author = $message->getAuthor();
        $thread = $message->getThread();

        try {
            $url = $this->urlGenerator->generate('messages_thread', ['publicId' => $thread->getPublicId()]);
        } catch (RoutingException) {
            $url = '';
        }

        $this->dispatcher->dispatch(
            self::EVENT_NEW,
            $recipient,
            [
                'thread_public_id' => $thread->getPublicId(),
                'preview' => mb_substr(trim(strip_tags($message->getBody())), 0, 120),
                'actor_name' => $author?->getPublicDisplayName() ?? '',
                'url' => $url,
            ],
            $author,
            new NotificationSubject('message_thread', $thread->getId()),
        );
    }
}
