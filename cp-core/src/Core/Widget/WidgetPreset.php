<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * A ready-made set of widgets for an area. Each entry names a widget type and may override its title
 * and settings; anything left out takes the type's defaults.
 */
final readonly class WidgetPreset
{
    /**
     * @param list<array{type: string, title?: string, settings?: array<string, mixed>}> $widgets
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public array $widgets,
    ) {
    }
}
