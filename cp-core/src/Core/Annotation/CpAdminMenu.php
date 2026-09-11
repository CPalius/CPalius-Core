<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Marks a controller action as a Studio or AACP sidebar menu item (TARGET_METHOD, not class).
 * Module/capability filtering is deferred to AdminMenuRuntime at render time (see AdminMenuRegistrationPass).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpAdminMenu
{
    /**
     * @param string $label Sidebar label text.
     * @param string $icon symfony/ux-icons name (e.g. "heroicons:home").
     * @param string $panel Shell: "studio" | "aacp".
     * @param int $priority Lower values sort first (default 100).
     * @param string|null $capability Required capability; pipe-separated OR (e.g. "node.post.view.own|node.post.view.any"). null = authenticated only.
     * @param string|null $group Sidebar section heading. null = ungrouped.
     * @param string|null $parent Parent route name for nested submenu (see AdminMenuRuntime::render()).
     */
    public function __construct(
        public readonly string $label,
        public readonly string $icon = 'heroicons:squares-2x2',
        public readonly string $panel = 'studio',
        public readonly int $priority = 100,
        public readonly ?string $capability = null,
        public readonly ?string $group = null,
        public readonly ?string $parent = null,
    ) {
    }
}
