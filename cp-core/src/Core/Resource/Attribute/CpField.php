<?php

declare(strict_types=1);

namespace App\Core\Resource\Attribute;

/**
 * Presentation metadata for one column of a #[CpResource] entity, read by the
 * auto-generated admin (list + form). Optional — sensible defaults come from
 * Doctrine metadata. Not to be confused with the Field API (Node bundle fields).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CpField
{
    /**
     * @param string|null $label     Translation key or literal; null = humanised property name.
     * @param bool        $list       Show as a column in the list screen.
     * @param bool        $form       Show as an input in the create/edit form.
     * @param bool        $readonly   Render in the form but never bind (display only).
     * @param bool        $sortable   Allow ordering the list by this column.
     * @param bool        $searchable Include in the list search (string columns only).
     * @param int         $priority   Lower = earlier (both list columns and form order).
     * @param string|null $widget     Force a form widget: text|textarea|email|url|number|checkbox|date|datetime|choice|reference.
     */
    public function __construct(
        public readonly ?string $label = null,
        public readonly bool $list = true,
        public readonly bool $form = true,
        public readonly bool $readonly = false,
        public readonly bool $sortable = false,
        public readonly bool $searchable = false,
        public readonly int $priority = 0,
        public readonly ?string $widget = null,
    ) {
    }
}
