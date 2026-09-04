<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use App\Entity\User;
use Modules\Forum\Service\ForumBanService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Blocks forum-banned users from all front controllers by namespace.
 * Studio Admin controllers are excluded so a banned moderator keeps ACP access; MUTE is checked on write actions.
 */
final class ForumBanGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumBanService $banService,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $controllerObject = \is_array($controller) ? $controller[0] : $controller;
        $controllerClass = $controllerObject::class;

        $isForumFrontController = str_starts_with($controllerClass, 'Modules\\Forum\\Controller\\')
            && !str_starts_with($controllerClass, 'Modules\\Forum\\Controller\\Admin\\');

        if (!$isForumFrontController) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $ban = $this->banService->activeBanFor($user);
        if ($ban === null) {
            return;
        }

        throw new AccessDeniedHttpException(sprintf('Forumdan yasaklandınız: %s', $ban->getReason()));
    }
}
