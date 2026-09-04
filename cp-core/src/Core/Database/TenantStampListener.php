<?php

declare(strict_types=1);

namespace App\Core\Database;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Law 5.1 write side: stamp tenant_id on persist. Does not overwrite an already-set tenant.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantStampListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof TenantAwareInterface) {
            return;
        }

        if ($entity->getTenantId() !== null) {
            return;
        }

        if (!$this->tenantContext->has()) {
            return;
        }

        $entity->setTenantId($this->tenantContext->get());
    }
}
