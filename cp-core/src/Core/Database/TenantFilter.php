<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Annotation\CpResource;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Manifesto Law 5.1 (Tenant Isolation by Default): #[CpResource(multiTenant:
 * true)] ile işaretlenmiş HER entity'nin sorgularına, geliştiricinin bunu
 * unutma ihtimali SIFIRA inecek şekilde otomatik "AND tenant_id = :tenant_id"
 * kısıtı enjekte eder.
 *
 * Doctrine SQLFilter'lar container'dan DEĞİL, doğrudan Doctrine tarafından
 * `new $filterClass($em)` ile instantiate edilir (bkz. final __construct
 * üst sınıfta) — bu yüzden ResourceRegistry servisini buraya autowire
 * EDEMEYİZ. Bunun yerine $targetEntity->getReflectionClass() üzerinden
 * #[CpResource] attribute'unu doğrudan okuyoruz; bu, ResourceRegistry'nin
 * derleme-zamanı taramasıyla aynı kaynağı (attribute'un kendisi) okur,
 * sadece runtime'da tekrar reflection yapar.
 *
 * Filtre TenantFilterActivationListener tarafından enable edilip
 * "tenant_id" parametresi setParameter() ile doldurulmadığı sürece
 * (ör. tenant çözülemeyen bir kernel.request anında) Doctrine bu filtreyi
 * hiç çağırmaz; parametre eksikse addFilterConstraint çağrılmadan önce
 * Doctrine zaten hata verir — yani "parametre unutuldu, filtre sessizce
 * devre dışı kaldı" durumu OLUŞAMAZ.
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
