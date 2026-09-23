<?php

declare(strict_types=1);

namespace Modules\Importer\Source;

use App\Core\Migrate\MigrationSourceInterface;

/**
 * A step that has nothing to read — the operator skipped the files folder.
 */
final class EmptyMigrationSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly string $describedAs,
    ) {
    }

    public function describe(): string
    {
        return $this->describedAs;
    }

    public function rows(): iterable
    {
        return [];
    }

    public function count(): int
    {
        return 0;
    }
}
