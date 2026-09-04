<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

/**
 * Coarse regex for the primary table name from SQL. Not a full parser: unknown SQL returns null (fail-open).
 * Only SELECT is counted so bulk writes (settings flush) do not trip the N+1 guard.
 */
final class QueryCounterTableParser
{
    private function __construct()
    {
    }

    public static function extractTable(string $sql): ?string
    {
        $normalized = ltrim($sql);

        if (preg_match('/^SELECT\b.*?\bFROM\s+`?([a-zA-Z0-9_]+)`?/is', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
