<?php

declare(strict_types=1);

namespace App\Core\Asset;

use App\Core\Module\ActiveModulesKeeper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A page render compiles styles/app.css on the fly when the asset manifest
 * is missing. That compile reads var/tailwind, which an update does not
 * write. Seeding before the controller runs is what keeps the first request
 * after a wiped manifest from 500ing.
 */
final class TailwindBuildSeedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 2048]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        (new TailwindBuildSeeder($this->projectDir))->seed();
        (new ActiveModulesKeeper($this->projectDir))->restoreFromLatestBackup();
    }
}
