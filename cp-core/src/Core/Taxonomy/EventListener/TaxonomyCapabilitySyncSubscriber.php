<?php

declare(strict_types=1);

namespace App\Core\Taxonomy\EventListener;

use App\Core\Taxonomy\TaxonomyCapabilityRegistrar;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures per-vocabulary capabilities exist before CPaliusVoter evaluates them.
 */
final class TaxonomyCapabilitySyncSubscriber implements EventSubscriberInterface
{
    private bool $synced = false;

    public function __construct(
        private readonly TaxonomyCapabilityRegistrar $registrar,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->synced) {
            return;
        }

        try {
            $this->registrar->sync();
        } catch (\Throwable) {
            // DB unavailable during install — static taxonomy.manage still works.
        }

        $this->synced = true;
    }
}
