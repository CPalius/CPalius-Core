<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * #[CpResource(multiTenant: true)] ile işaretlenmiş bir entity'nin,
 * TenantStampListener'ın prePersist sırasında tenant_id'yi yazabilmesi
 * için uyması gereken sözleşme. TenantFilter (okuma tarafı) ham SQL/
 * ClassMetadata ile çalıştığı için bu arayüze ihtiyaç duymaz; bu arayüz
 * sadece YAZMA tarafı (Manifesto Law 5.1: "Writes must be intercepted by
 * a prePersist listener") için gereklidir.
 */
interface TenantAwareInterface
{
    public function getTenantId(): int|string|null;

    public function setTenantId(int|string $tenantId): void;
}
