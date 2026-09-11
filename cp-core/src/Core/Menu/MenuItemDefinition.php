<?php

declare(strict_types=1);

namespace App\Core\Menu;

/**
 * Immutable compile-time definition for one #[CpAdminMenu] controller action.
 * Pure data carrier like ResourceDefinition; no behavior or filtering logic.
 */
final class MenuItemDefinition
{
    /**
     * @param string $module Hosting module bundle FQCN (e.g. Modules\Blog\BlogModule) or "core".
     */
    public function __construct(
        public readonly string $label,
        public readonly string $icon,
        public readonly string $panel,
        public readonly int $priority,
        public readonly ?string $capability,
        public readonly ?string $group,
        public readonly string $routeName,
        public readonly string $routePrefix,
        public readonly string $module,
        public readonly string $controllerClass,
        public readonly string $method,
        public readonly ?string $parent = null,
    ) {
    }
}
