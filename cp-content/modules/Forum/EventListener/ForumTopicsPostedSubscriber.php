<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use Modules\Forum\Event\ForumPostCreatedEvent;
use Modules\Forum\Service\ForumParticipationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ForumTopicsPostedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumParticipationService $participationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ForumPostCreatedEvent::NAME => 'onPostCreated',
        ];
    }

    public function onPostCreated(ForumPostCreatedEvent $event): void
    {
        $this->participationService->markPosted($event->getAuthor(), $event->getTopic());
    }
}
