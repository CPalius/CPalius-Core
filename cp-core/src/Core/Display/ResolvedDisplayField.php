<?php

declare(strict_types=1);

namespace App\Core\Display;

use App\Core\Field\Entity\FieldDefinition;

/**
 * One field's effective display in one (bundle, view mode) — either an
 * explicit EntityDisplay override or the field's own defaults.
 */
final class ResolvedDisplayField
{
    public function __construct(
        public readonly FieldDefinition $definition,
        public readonly bool $visible,
        public readonly int $weight,
        public readonly string $labelDisplay,
    ) {
    }
}
