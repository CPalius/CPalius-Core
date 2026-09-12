<?php

declare(strict_types=1);

namespace App\Core\Migrate\Source;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * Reads a delimited file one row at a time.
 *
 * Streams with fgetcsv rather than str_getcsv over file_get_contents, so the
 * memory cost is one row regardless of file size. That is the difference
 * between importing a 40 MB customer export and being told the import "just
 * needs more memory" — the excuse every CMS importer eventually offers.
 *
 * A UTF-8 BOM is stripped from the first header cell. Exports from Excel carry
 * one, and without this the first column is named "\u{FEFF}id" and every lookup
 * of "id" silently misses, which presents as "the id column is empty".
 */
final class CsvSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly string $path,
        private readonly string $idColumn,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
    ) {
        if (\strlen($delimiter) !== 1 || \strlen($enclosure) !== 1) {
            throw new \InvalidArgumentException('CSV delimiter and enclosure must each be a single character.');
        }
    }

    public function describe(): string
    {
        return sprintf('CSV file %s (key column "%s")', $this->path, $this->idColumn);
    }

    public function rows(): iterable
    {
        $handle = $this->open();

        try {
            $header = $this->readHeader($handle);
            $lineNumber = 1;

            while (($values = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, '')) !== false) {
                ++$lineNumber;

                // fgetcsv yields [null] for a blank line; skipping it is not
                // lenience, it is the difference between a trailing newline and
                // a failed row in every report.
                if ($values === [null]) {
                    continue;
                }

                $data = $this->combine($header, $values, $lineNumber);
                $sourceId = (string) ($data[$this->idColumn] ?? '');

                if (trim($sourceId) === '') {
                    throw new \RuntimeException(sprintf('Row on line %d of %s has no value in the key column "%s". Without a stable key the row cannot be re-imported without duplicating it.', $lineNumber, $this->path, $this->idColumn));
                }

                yield new MigrationRow($sourceId, $data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Counts data rows without holding the file in memory.
     *
     * Worth the second pass here, unlike a streamed remote export: the file is
     * local, and an operator about to import 12 000 customers wants to be told
     * so before it starts rather than after.
     */
    public function count(): int
    {
        $handle = $this->open();

        try {
            $this->readHeader($handle);
            $count = 0;

            while (($values = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                ++$count;
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException(sprintf('CSV source file "%s" does not exist or cannot be read.', $this->path));
        }

        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open CSV source file "%s".', $this->path));
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, '');

        if ($header === false || $header === [null]) {
            throw new \RuntimeException(sprintf('CSV source file "%s" is empty; expected a header row.', $this->path));
        }

        $columns = [];

        foreach ($header as $index => $column) {
            $name = (string) $column;

            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $columns[] = trim($name);
        }

        if (!\in_array($this->idColumn, $columns, true)) {
            throw new \RuntimeException(sprintf('CSV source file "%s" has no column "%s". Columns found: %s.', $this->path, $this->idColumn, implode(', ', $columns)));
        }

        return $columns;
    }

    /**
     * @param list<string>      $header
     * @param list<string|null> $values
     *
     * @return array<string, string>
     */
    private function combine(array $header, array $values, int $lineNumber): array
    {
        if (\count($header) !== \count($values)) {
            throw new \RuntimeException(sprintf('Line %d of %s has %d values but the header declares %d columns. Guessing which column is missing would put data in the wrong field.', $lineNumber, $this->path, \count($values), \count($header)));
        }

        $row = [];

        foreach ($header as $index => $column) {
            $row[$column] = (string) ($values[$index] ?? '');
        }

        return $row;
    }
}
