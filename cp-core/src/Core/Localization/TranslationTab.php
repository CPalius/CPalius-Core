<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * One admin language tab: scalars only (id/label), no entity, so one Twig component works for any translatable type.
 */
final class TranslationTab
{
    public function __construct(
        public readonly string $code,
        public readonly string $nativeName,
        /** True when a row exists in this locale (otherwise the "add translation" tab). */
        public readonly bool $exists,
        /** Sibling row id; null when exists is false. */
        public readonly ?int $id,
        /** Whether this is the locale of the row being edited. */
        public readonly bool $isCurrent,
        /** Sibling display name (tooltip); null when exists is false. */
        public readonly ?string $label = null,
    ) {
    }
}
