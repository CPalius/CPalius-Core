<?php

declare(strict_types=1);

namespace App\Core\Widget;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Draws an area. Implemented by the Widget module; themes reach it only through cp_widget_area(), which
 * returns '' when no renderer exists, so a site without the module still renders.
 */
#[AutoconfigureTag('cpalius.widget_area_renderer')]
interface WidgetAreaRendererInterface
{
    /**
     * @param array<string, mixed> $context keys: page (string), subject (object|null), locale (string|null)
     */
    public function render(string $area, array $context = []): string;
}
