<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Law 6.1: count SELECTs per table in one HTTP request; over the limit throws MaxQueriesExceededException.
 * Writes are ignored so a settings flush is not a false N+1.
 */
final class QueryCounter
{
    /** @var array<string, int> table name => SELECT count in this request */
    private array $countsByTable = [];

    public function __construct(
        private readonly int $maxQueriesPerTable = 10,
    ) {
    }

    /**
     * @throws MaxQueriesExceededException when this table exceeds the per-request limit
     */
    public function increment(string $table): void
    {
        $count = ($this->countsByTable[$table] ?? 0) + 1;
        $this->countsByTable[$table] = $count;

        if ($count > $this->maxQueriesPerTable) {
            throw MaxQueriesExceededException::forTable($table, $count, $this->maxQueriesPerTable);
        }
    }

    /**
     * Clear counts at the start of each HTTP request so PHP-FPM workers do not leak the previous request.
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
