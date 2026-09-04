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
            'N+1 sorgu tespit edildi: "%s" tablosuna bu istek içinde %d kez sorgu atıldı (limit: %d). '
            .'Muhtemelen bir döngü içinde lazy-loading yapılıyor; ilişkiyi bir fetch-join (JOIN FETCH) '
            .'veya batch sorgu ile önceden yükleyin.',
            $table,
            $count,
            $limit,
        ));
    }
}
