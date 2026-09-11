<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Law 6.1: thrown in dev when one HTTP request exceeds the per-table SELECT limit. Not registered in prod.
 */
final class MaxQueriesExceededException extends \RuntimeException
{
    public static function forTable(string $table, int $count, int $limit): self
    {
        return new self(sprintf(
            'N+1 query detected: table "%s" was queried %d times in this request (limit: %d). '
            .'Lazy-loading is likely happening inside a loop; preload the relation with a fetch-join (JOIN FETCH) '
            .'or a batch query.',
            $table,
            $count,
            $limit,
        ));
    }
}
