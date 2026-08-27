<?php

declare(strict_types=1);

namespace App\Core\Menu;

/**
 * #[CpAdminMenu] ile işaretlenmiş TEK bir controller action'ının derleme
 * zamanında çözülmüş, değişmez tanımı. ResourceDefinition ile aynı ruhta:
 * saf veri taşıyıcısı, hiçbir davranış/filtreleme mantığı içermez.
 */
final class MenuItemDefinition
{
    /**
     * @param string $module Bu action'ı barındıran modülün bundle FQCN'i
     *   (ör. "Modules\Blog\BlogModule"), çekirdek controller'lar için "core".
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
