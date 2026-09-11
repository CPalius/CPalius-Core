<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Compositional attribute: entity uses recycle-bin soft delete via deletedAt (implementation in SoftDeletableTrait).
 * $retentionDays is a policy hint for cleanup cron; this attribute does not perform deletion itself.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SoftDeletable
{
    public function __construct(
        public readonly int $retentionDays = 30,
    ) {
    }
}
