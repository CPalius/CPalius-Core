<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

/**
 * Ham SQL metninden birincil tablo adını çıkaran, bilinçli olarak "kaba"
 * bir regex ayrıştırıcı. Tam bir SQL parser YAZMIYORUZ: amaç bir SQL
 * doğrulayıcısı değil, dev ortamında N+1'i yakalayacak kadar güvenilir
 * bir sinyal üretmektir. Tanınmayan/karmaşık bir sorgu (ör. çok karmaşık
 * bir CTE) için null dönmesi kabul edilebilir — o durumda sayaç sadece o
 * sorguyu saymaz, guard'ı hiç bozmaz (fail-open, çünkü bu bir güvenlik
 * kontrolü değil bir geliştirici yardımcısıdır).
 *
 * Yalnızca SELECT sayılır: N+1 lazy-loading bir okuma sorunudur. Ayar
 * kaydı gibi meşru toplu UPDATE/INSERT/DELETE'ler (ör. cp_settings'e
 * 11 satır yazmak) bu guard'ı tetiklememelidir.
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
