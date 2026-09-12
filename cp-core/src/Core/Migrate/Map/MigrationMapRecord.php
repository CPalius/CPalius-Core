<?php

declare(strict_types=1);

namespace App\Core\Migrate\Map;

/**
 * What the map remembers about one imported row.
 *
 * A plain value object rather than the Doctrine entity, so the runner and its
 * tests never depend on the ORM, and an in-memory map is a real implementation
 * instead of a mock.
 */
final class MigrationMapRecord
{
    public function __construct(
        public readonly string $migrationId,
        public readonly string $sourceId,
        public readonly string $checksum,
        public readonly string $destinationType,
        public readonly string $destinationId,
    ) {
    }
}
