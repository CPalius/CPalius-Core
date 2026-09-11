<?php

declare(strict_types=1);

namespace App\Core\Field\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Theme-facing field helpers. Logic lives in FieldRuntime (lazy-loaded).
 *
 *   {{ cp_field(node, 'subtitle') }}          rendered HTML for one field
 *   {{ cp_field_value(node, 'price') }}        raw stored value
 *   {{ cp_fields(node) }}                       every visible field, wrapped
 *   {{ cp_fields(node, 'contact') }}            one field group
 *   {% if cp_has_field(node, 'hero') %}         presence test
 *   {% do cp_fields_preload(nodes) %}           batch reference loading for a list
 *   {{ cp_entity_view(node, 'teaser') }}        fields visible in one view mode (T2.2)
 */
final class FieldExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        $html = ['is_safe' => ['html']];

        return [
            new TwigFunction('cp_field', [FieldRuntime::class, 'field'], $html),
            new TwigFunction('cp_field_value', [FieldRuntime::class, 'value']),
            new TwigFunction('cp_fields', [FieldRuntime::class, 'fields'], $html),
            new TwigFunction('cp_has_field', [FieldRuntime::class, 'has']),
            new TwigFunction('cp_fields_preload', [FieldRuntime::class, 'preload']),
            new TwigFunction('cp_entity_view', [FieldRuntime::class, 'view'], $html),
        ];
    }
}
