<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Active tenant id. SQLFilter cannot be autowired; TenantFilterActivationListener copies this into the filter.
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
