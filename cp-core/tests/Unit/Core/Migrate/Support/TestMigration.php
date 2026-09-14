<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate\Support;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * A migration assembled from parts the test supplies.
 */
final class TestMigration implements MigrationInterface
{
    /**
     * @param list<string>                                 $dependsOn
     * @param (\Closure(MigrationRow): ?MigrationRow)|null $transform
     */
    public function __construct(
        private readonly string $id,
        private readonly MigrationSourceInterface $source,
        private readonly MigrationDestinationInterface $destination,
        private readonly array $dependsOn = [],
        private readonly ?\Closure $transform = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return 'Test migration '.$this->id;
    }

    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function source(): MigrationSourceInterface
    {
        return $this->source;
    }

    public function destination(): MigrationDestinationInterface
    {
        return $this->destination;
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        return $this->transform === null ? $row : ($this->transform)($row);
    }
}
