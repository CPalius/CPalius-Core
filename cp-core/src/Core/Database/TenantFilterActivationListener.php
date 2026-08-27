<?php

declare(strict_types=1);

namespace App\Core\Database;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * TenantContext'te bir tenant kimliği varsa (bkz. TenantContext dokümanı —
 * bu context'in NASIL doldurulacağı, ör. subdomain/header çözümlemesi,
 * bu sınıfın sorumluluğu DEĞİLDİR, ayrı bir TenantResolver katmanına
 * aittir), Doctrine'in "tenant" SQLFilter'ını (bkz. TenantFilter) her
 * istek için etkinleştirir ve tenant_id parametresini doldurur.
 *
 * En yüksek öncelikle (early) çalışır: bu listener'dan SONRA çalışacak
 * her repository/query çağrısının tenant izolasyonundan faydalanabilmesi
 * gerekir — bu yüzden controller'lardan önce, mümkün olduğunca erken
 * tetiklenmelidir.
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
