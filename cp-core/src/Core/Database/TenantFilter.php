<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Annotation\CpResource;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Law 5.1: inject AND tenant_id = :tenant_id on #[CpResource(multiTenant: true)] queries.
 * SQLFilter is not a container service; #[CpResource] is read via reflection. Disabled until enabled + parameterized.
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$this->isMultiTenantEntity($targetEntity)) {
            return '';
        }

        if (!$this->hasParameter('tenant_id')) {
            return '';
        }

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $this->getParameter('tenant_id'));
    }

    /**
     * @param ClassMetadata<object> $targetEntity
     */
    private function isMultiTenantEntity(ClassMetadata $targetEntity): bool
    {
        $attributes = $targetEntity->getReflectionClass()->getAttributes(CpResource::class);

        if ($attributes === []) {
            return false;
        }

        /** @var CpResource $resource */
        $resource = $attributes[0]->newInstance();

        return $resource->multiTenant;
    }
}
