<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * "Şu an aktif olan tenant kimdir?" sorusunun tek doğruluk kaynağı.
 *
 * Doctrine\ORM\Query\Filter\SQLFilter, Doctrine tarafından doğrudan
 * `new $filterClass($em)` ile instantiate edilir — DI container'dan
 * autowire EDİLEMEZ, bu yüzden TenantFilter'a mevcut tenant_id'yi
 * constructor injection ile veremeyiz. Bunun yerine: bu servis normal
 * şekilde autowire edilir (ör. bir TenantResolverListener tarafından
 * subdomain/header'dan çözülüp set() ile doldurulur), sonra
 * TenantFilterActivationListener bu context'i okuyup
 * $em->getFilters()->enable('tenant')->setParameter('tenant_id', ...)
 * çağrısıyla SQLFilter'a "enjekte eder". TenantFilter'ın kendisi sadece
 * Doctrine'in setParameter() ile doldurduğu değeri okur.
 */
final class TenantContext
{
    private int|string|null $tenantId = null;

    public function set(int|string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function get(): int|string|null
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
