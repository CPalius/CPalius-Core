<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wipes var/cache/{env} after the response is already sent.
 */
final class CacheRebuildTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheRebuildManager $cacheRebuildManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->cacheRebuildManager->flushDeferredKernelPurge();
    }
}
