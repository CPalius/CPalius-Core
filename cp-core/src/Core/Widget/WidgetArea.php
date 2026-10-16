<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * A place in a theme where widgets are drawn ({{ cp_widget_area('blog_sidebar') }}).
 *
 * `module` is the module dir name that owns the area ("Blog", "Forum"…); the Studio groups areas by it and
 * offers that module's own widget types first. Empty for a theme's own areas.
 *
 * `presets` are ready-made arrangements the admin can apply in one click; the one named by
 * `defaultPreset` is what a new site (or a site that had no widgets yet) starts with.
 */
final readonly class WidgetArea
{
    /**
     * @param list<WidgetPreset> $presets
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description = '',
        public array $presets = [],
        public string $defaultPreset = '',
        public string $module = '',
    ) {
    }
}
