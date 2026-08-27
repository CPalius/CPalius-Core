<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Manifesto Law 6.1 (Dev-Mode N+1 Exception Guard) giriş noktası.
 *
 * DoctrineBundle, "doctrine.middleware" etiketli servisleri otomatik
 * olarak DBAL Driver zincirine ekler (bkz. services.yaml). Bu middleware
 * SADECE dev ortamında register edilir (services.yaml'daki when@dev
 * bloğu) — prod'da bu sınıf hiç instantiate edilmez, dolayısıyla prod
 * sorgu performansına hiçbir maliyeti yoktur.
 */
final class QueryCounterMiddleware implements Middleware
{
    public function __construct(
        private readonly QueryCounter $counter,
    ) {
    }

    /**
     * N+1 Guard'ın amacı, gerçek kullanıcı isteklerinde (web) sayfa başına
     * kontrolsüz sorgu patlamasını geliştirme aşamasında yakalamaktır.
     * CLI süreçleri (bin/console komutları: migrations, cache:warmup,
     * fixtures, cp:user:create-admin vb.) doğası gereği toplu/introspektif
     * sorgular atar — ör. doctrine:migrations:status information_schema'yı
     * defalarca sorgular. Bu tamamen normaldir ve bir N+1 hatası DEĞİLDİR;
     * guard'ı burada da aktif tutmak CLI araçlarını gereksiz yere kilitler.
     *
     * Bu yüzden CLI'da hiç QueryCounterDriver'a sarmalamadan ham $driver'ı
     * döneriz: sayaç tamamen devre dışı kalır, sıfır overhead.
     */
    public function wrap(Driver $driver): Driver
    {
        if (\PHP_SAPI === 'cli') {
            return $driver;
        }

        return new QueryCounterDriver($driver, $this->counter);
    }
}
