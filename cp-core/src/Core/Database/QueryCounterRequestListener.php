<?php

declare(strict_types=1);

namespace App\Core\Database;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reset QueryCounter on each master HTTP request so PHP-FPM workers do not leak counts.
 * Sub-requests (ESI) share the same budget as the master response.
 */
final class QueryCounterRequestListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly QueryCounter $counter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->counter->reset();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
