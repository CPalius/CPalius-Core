<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Security\Service\AdminIpAllowlist;
use App\Core\Security\UserRoleGuardService;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Adds the address of a signed-in administrator to the session allowlist and removes it again on logout. */
final class AdminIpAllowlistSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AdminIpAllowlist $allowlist,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) so the token is known.
        return [
            KernelEvents::REQUEST => ['onRequest', 4],
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasSession() || !$event->getRequest()->getSession()->isStarted()) {
            return;
        }

        try {
            $user = $this->security->getUser();
            if ($user instanceof User && \in_array(UserRoleGuardService::ROLE_ADMIN, $user->getCpaliusRoles(), true)) {
                $this->allowlist->touch((string) $event->getRequest()->getClientIp());
            }
        } catch (\Throwable) {
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->allowlist->forget((string) $event->getRequest()->getClientIp());
    }
}
