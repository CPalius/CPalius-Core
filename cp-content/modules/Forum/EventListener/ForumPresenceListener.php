<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use App\Entity\User;
use Modules\Forum\Service\ForumPresenceService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records forum visitors so the stats bar can show who is online.
 */
final class ForumPresenceListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumPresenceService $presenceService,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || !str_starts_with($route, 'forum_')) {
            return;
        }

        $user = $this->security->getUser();
        $topicId = $request->attributes->get('topicId');

        if ($user instanceof User) {
            if (!$request->hasSession()) {
                return;
            }

            $session = $request->getSession();
            if (!$session->isStarted()) {
                $session->start();
            }

            $hash = $this->presenceService->hashSessionId($session->getId());
        } else {
            // Guests must not start PHPSESSID — that cookie blocks origin HTML cache site-wide.
            $hash = $this->presenceService->hashAnonymousVisitor(
                (string) $request->getClientIp(),
                (string) $request->headers->get('User-Agent', ''),
            );
        }

        $this->presenceService->touch(
            $hash,
            $user instanceof User ? $user : null,
            is_numeric($topicId) ? (int) $topicId : null,
        );
    }
}
