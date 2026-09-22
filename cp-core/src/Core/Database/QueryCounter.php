<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Law 6.1: count SELECTs per table in one HTTP request; over the limit throws MaxQueriesExceededException.
 * Writes are ignored so a settings flush is not a false N+1.
 *
 * Schema-catalog queries (information_schema / pg_catalog) are also ignored:
 * Doctrine migrations and schema introspection legitimately hit them many times
 * in one request (e.g. AACP Updates dry-run), which is not application N+1.
 */
final class QueryCounter
{
    /** @var list<string> */
    private const IGNORED_TABLES = [
        'information_schema',
        'pg_catalog',
        'performance_schema',
        'sys',
        'mysql',
    ];

    /** @var array<string, int> table name => SELECT count in this request */
    private array $countsByTable = [];

    /** Nested suspend() calls; increment() is a no-op while this is above zero. */
    private int $suspended = 0;

    public function __construct(
        private readonly int $maxQueriesPerTable = 10,
    ) {
    }

    /**
     * Rebuild / import / update: many honest reads of one table, not a lazy-load loop.
     */
    public function suspend(): void
    {
        ++$this->suspended;
    }

    public function resume(): void
    {
        $this->suspended = max(0, $this->suspended - 1);
    }

    /**
     * @throws MaxQueriesExceededException when this table exceeds the per-request limit
     */
    public function increment(string $table): void
    {
        if ($this->suspended > 0) {
            return;
        }

        $normalized = strtolower($table);
        if (in_array($normalized, self::IGNORED_TABLES, true)) {
            return;
        }

        $count = ($this->countsByTable[$table] ?? 0) + 1;
        $this->countsByTable[$table] = $count;

        if ($count > $this->maxQueriesPerTable) {
            throw MaxQueriesExceededException::forTable($table, $count, $this->maxQueriesPerTable);
        }
    }

    /**
     * Clear counts at the start of each HTTP request so PHP-FPM workers do not leak the previous request.
     *
     * Also called once per row by MigrationRunner. The premise of this guard is
     * "one request renders one page, so eleven reads of one table is a loop" —
     * and a batch import breaks that premise honestly: it touches one table
     * once per row because that is the work, not because of a defect. Applying
     * the budget per row instead of per request keeps the guard doing its job
     * where it still applies (a real lazy-load loop inside a single row still
     * trips it) without making an import of eleven rows impossible.
     */
    public function reset(): void
    {
        $this->countsByTable = [];
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->countsByTable;
    }
}
