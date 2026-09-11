<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Binds a Doctrine entity to CPalius metadata-driven platform (capabilities, audit, multi-tenant).
 * Short $capabilities (e.g. create, edit) expand to full names like vehicle.create (see ResourceRegistry).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CpResource
{
    /**
     * @param string $name Short unique resource id (capability prefix: "<name>.<capability>").
     * @param string $module Owning module id; "core" for core entities.
     * @param list<string> $capabilities Short action names (e.g. create, edit, delete, view).
     * @param bool $auditable Record changes in audit log when true.
     * @param bool $multiTenant TenantFilter injects tenant_id on queries when true.
     * @param string|null $workflow Symfony Workflow state machine name, or null.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $module = 'core',
        public readonly array $capabilities = [],
        public readonly bool $auditable = false,
        public readonly bool $multiTenant = false,
        public readonly ?string $workflow = null,
    ) {
    }
}
