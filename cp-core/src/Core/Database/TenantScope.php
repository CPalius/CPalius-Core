<?php

declare(strict_types=1);

namespace App\Core\Database;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies TenantContext and enables the SQL filter in the current process.
 * Needed because TenantFilterActivationListener only runs on HTTP request start.
 */
final class TenantScope
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(int|string $tenantId): void
    {
        $this->tenantContext->set($tenantId);

        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled('tenant')) {
            $filters->enable('tenant');
        }

        $filters->getFilter('tenant')->setParameter('tenant_id', (string) $tenantId);
    }

    public function applyOptional(?string $tenantId): void
    {
        if ($tenantId === null || $tenantId === '') {
            return;
        }

        $this->apply($tenantId);
    }
}
