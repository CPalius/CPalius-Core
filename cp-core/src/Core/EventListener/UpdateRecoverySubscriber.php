<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Version\Service\AtomicUpdateInstaller;
use App\Core\Version\Service\DatabaseUpdateGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Finishes the database half of a crash recovery on the first request that boots the kernel.
 * The file half already ran in public/index.php.
 */
final class UpdateRecoverySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AtomicUpdateInstaller $installer,
        private readonly DatabaseUpdateGuard $guard,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 255]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // One stat() per request; the state file only exists around updates.
        if (!$event->isMainRequest() || !$this->installer->maintenanceActive()) {
            return;
        }

        try {
            $this->guard->completePendingRestore();
        } catch (\Throwable) {
            // The state stays on disk, so the next request tries again.
        }
    }
}
