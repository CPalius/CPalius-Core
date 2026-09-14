<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate\Support;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * A source backed by rows held in the test.
 *
 * A real implementation rather than a mock, so the runner tests exercise the
 * actual iteration contract — including that the runner stops pulling rows once
 * a limit is hit, which a mock's expectations would happily fake.
 */
final class ArraySource implements MigrationSourceInterface
{
    public int $rowsPulled = 0;

    /**
     * @param list<MigrationRow> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function describe(): string
    {
        return sprintf('%d rows held in the test', \count($this->rows));
    }

    public function rows(): iterable
    {
        foreach ($this->rows as $row) {
            ++$this->rowsPulled;

            yield $row;
        }
    }

    public function count(): int
    {
        return \count($this->rows);
    }
}
