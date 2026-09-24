<?php

declare(strict_types=1);

namespace Modules\ThrowingListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ordinary EventSubscriberInterface on kernel.request — exactly how a module
 * would wire itself directly onto Symfony's dispatcher instead of going
 * through the isolated #[CpHook] extension point. Always throws, so
 * ModuleIsolationTest can prove ModuleEventListenerGuardPass catches it.
 */
final class BrokenRequestSubscriber implements EventSubscriberInterface
{
    public const FAILURE_MESSAGE = 'BrokenRequestSubscriber kasitli olarak kernel.request sirasinda patladi.';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}
