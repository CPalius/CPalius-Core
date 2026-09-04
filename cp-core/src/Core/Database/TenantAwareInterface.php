<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Write-side contract so TenantStampListener can set tenant_id on prePersist (Law 5.1).
 * TenantFilter (reads) uses ClassMetadata and does not need this interface.
 */
interface TenantAwareInterface
{
    public function getTenantId(): int|string|null;

    public function setTenantId(int|string $tenantId): void;
}
