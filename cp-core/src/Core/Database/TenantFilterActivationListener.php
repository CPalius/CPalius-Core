<?php

declare(strict_types=1);

namespace App\Core\Database;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enable Doctrine's tenant SQLFilter and set tenant_id when TenantContext is filled.
 * Runs early (priority 250) so later queries are already isolated. Does not resolve the tenant.
 */
final class TenantFilterActivationListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->tenantContext->has()) {
            return;
        }

        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled('tenant')) {
            $filters->enable('tenant');
        }

        $filters->getFilter('tenant')->setParameter('tenant_id', $this->tenantContext->get());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
        ];
    }
}
