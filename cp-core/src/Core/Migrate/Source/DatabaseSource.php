<?php

declare(strict_types=1);

namespace App\Core\Migrate\Source;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * Rows out of a foreign database, read in batches.
 *
 * WHY KEYSET PAGING RATHER THAN LIMIT/OFFSET
 * The obvious way to walk a big table is LIMIT 500 OFFSET n, and it is wrong
 * twice over. The database has to count past every skipped row, so page 4000
 * costs four thousand pages of work — the import gets slower the longer it
 * runs, which is exactly backwards. And an OFFSET walk is only correct if
 * nothing changes underneath it: on a forum that is still live, one deleted
 * row shifts everything after it and the import silently skips a record.
 * Reading WHERE key > lastSeen ORDER BY key is constant cost per page and
 * cannot skip, because it remembers a position rather than a count.
 *
 * That is also what makes an interrupted import resumable at no extra cost:
 * the key is the same thing the map records, so a second run steps over what
 * is already there.
 *
 * SQL SAFETY
 * The FROM clause and columns come from driver code, never from operator
 * input; the only thing an operator supplies is the table prefix, which
 * ForeignDatabase validates as an identifier. Everything else — the paging
 * cursor above all — is a bound parameter.
 */
final class DatabaseSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly ForeignDatabase $database,
        /** FROM clause, e.g. "xf_user u LEFT JOIN xf_user_profile p ON p.user_id = u.user_id" */
        private readonly string $from,
        /** Qualified key column used both as the row id and the paging cursor. */
        private readonly string $keyColumn,
        private readonly string $select = '*',
        /** Extra condition, ANDed with the paging cursor. Driver-supplied. */
        private readonly string $where = '',
        private readonly int $batchSize = 500,
        private readonly string $describedAs = '',
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
    }

    public function describe(): string
    {
        return $this->describedAs !== '' ? $this->describedAs : sprintf('Database rows from %s', $this->from);
    }

    public function rows(): iterable
    {
        $connection = $this->database->connection();
        $cursor = null;

        while (true) {
            $conditions = [];
            $params = [];

            if ($this->where !== '') {
                $conditions[] = '('.$this->where.')';
            }

            if ($cursor !== null) {
                $conditions[] = $this->keyColumn.' > :cpCursor';
                $params['cpCursor'] = $cursor;
            }

            $sql = sprintf(
                'SELECT %s FROM %s%s ORDER BY %s ASC LIMIT %d',
                $this->select,
                $this->from,
                $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions),
                $this->keyColumn,
                $this->batchSize,
            );

            $batch = $connection->fetchAllAssociative($sql, $params);

            if ($batch === []) {
                return;
            }

            foreach ($batch as $record) {
                $key = $this->keyOf($record);

                if ($key === null) {
                    continue;
                }

                $cursor = $key;

                yield new MigrationRow((string) $key, $this->stringify($record));
            }

            if (\count($batch) < $this->batchSize) {
                return;
            }
        }
    }

    public function count(): ?int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s%s',
            $this->from,
            $this->where === '' ? '' : ' WHERE '.$this->where,
        );

        try {
            return (int) $this->database->connection()->fetchOne($sql);
        } catch (\Throwable) {
            // A total is a nicety; failing to produce one must not stop an
            // import that would otherwise run.
            return null;
        }
    }

    /**
     * The key is read from the unqualified column name, because that is what a
     * result row is keyed by whatever the SELECT called it.
     *
     * @param array<string, mixed> $record
     */
    private function keyOf(array $record): int|string|null
    {
        $column = str_contains($this->keyColumn, '.')
            ? substr($this->keyColumn, (int) strrpos($this->keyColumn, '.') + 1)
            : $this->keyColumn;

        $value = $record[$column] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    /**
     * Rows arrive as whatever the driver decided; the rest of the engine works
     * in strings, and a checksum over mixed int/string typing would report
     * unchanged rows as changed between drivers.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, string>
     */
    private function stringify(array $record): array
    {
        $row = [];

        foreach ($record as $column => $value) {
            if ($value === null) {
                $row[(string) $column] = '';

                continue;
            }

            if (\is_bool($value)) {
                $row[(string) $column] = $value ? '1' : '0';

                continue;
            }

            if (\is_scalar($value)) {
                $row[(string) $column] = (string) $value;

                continue;
            }

            if ($value instanceof \Stringable) {
                $row[(string) $column] = (string) $value;

                continue;
            }

            // Binary columns and anything exotic are dropped rather than
            // mangled into a string that would then be stored as content.
            $row[(string) $column] = '';
        }

        return $row;
    }
}
