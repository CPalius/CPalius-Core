<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Manifesto Law 6.1 (Dev-Mode N+1 Exception Guard): tek bir HTTP isteğinde
 * aynı tabloya karşı QueryCounter::MAX_QUERIES_PER_TABLE'ı aşan sayıda
 * sorgu atıldığında fırlatılır. Sadece dev ortamında etkindir (bkz.
 * services.yaml'daki when@dev bloğu) — prod'da bu guard hiç register
 * edilmez, dolayısıyla prod performansına sıfır maliyeti vardır.
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
