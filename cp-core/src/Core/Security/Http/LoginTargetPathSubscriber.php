<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ExceptionListener stores the last GET as target_path, including fetch()
 * polls that omit X-Requested-With. Drop those before login reads the session.
 */
final class LoginTargetPathSubscriber implements EventSubscriberInterface
{
    private const TARGET_KEY = '_security.main.target_path';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            if (!$request->hasSession()) {
                return;
            }

            $session = $request->getSession();
            if (!$session->isStarted() || !$session->has(self::TARGET_KEY)) {
                return;
            }

            $target = $session->get(self::TARGET_KEY);
            if (\is_string($target) && !LoginTargetPath::isNavigable($target)) {
                $session->remove(self::TARGET_KEY);
            }
        } catch (\Throwable) {
            // A sanitizer must never block the request.
        }
    }
}
