<?php

declare(strict_types=1);

namespace Modules\Seo\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * X-Robots-Tag for admin/AACP/account so those URLs never enter the index.
 */
final class SeoRobotsHeaderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -16]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (
            !str_starts_with($path, '/admin')
            && !str_starts_with($path, '/aacp')
            && $path !== '/login'
            && !str_contains($path, '/account')
            && !str_contains($path, '/hesap')
        ) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
