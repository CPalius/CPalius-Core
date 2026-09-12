<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate\Support;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;

/**
 * Records what was written, and can be told to fail for particular source ids
 * so per-row isolation is tested against something that really throws.
 */
final class RecordingDestination implements MigrationDestinationInterface
{
    /** @var list<array{sourceId: string, existingId: string|null}> */
    public array $writes = [];

    /** @var list<string> */
    public array $deletes = [];

    /**
     * @param list<string> $failFor        source ids whose write() throws
     * @param list<string> $deleteFailsFor destination ids whose delete() throws
     */
    public function __construct(
        private readonly array $failFor = [],
        private readonly array $deleteFailsFor = [],
    ) {
    }

    public function describe(): string
    {
        return 'test destination';
    }

    public function entityType(): string
    {
        return 'test';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        if (\in_array($row->sourceId, $this->failFor, true)) {
            throw new \RuntimeException(sprintf('row %s is broken', $row->sourceId));
        }

        $this->writes[] = ['sourceId' => $row->sourceId, 'existingId' => $existingId];

        return 'dest-'.$row->sourceId;
    }

    public function delete(string $destinationId): bool
    {
        if (\in_array($destinationId, $this->deleteFailsFor, true)) {
            throw new \RuntimeException(sprintf('cannot delete %s', $destinationId));
        }

        $this->deletes[] = $destinationId;

        return true;
    }
}
