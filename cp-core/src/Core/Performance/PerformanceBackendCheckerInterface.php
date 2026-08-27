<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Redis/Memcached/Varnish/PageSpeed prob sınıflarının paylaştığı ortak
 * sözleşme. AACPController/CacheRebuildManager'daki "asla dışarıya exception
 * fırlatma" ilkesiyle aynı: testConnection() her koşulda (eklenti yok,
 * bağlantı reddedildi, zaman aşımı) bir PerformanceCheckResult döner,
 * çağıran taraf try/catch yazmak zorunda kalmaz.
 */
interface PerformanceBackendCheckerInterface
{
    /**
     * @return string 'redis' | 'memcached' | 'varnish' | 'pagespeed'
     */
    public function getBackendId(): string;

    /**
     * @param array<string, mixed> $config Backend'e özel bağlantı ayarları
     *   (ör. Redis için host/port/password/timeout).
     */
    public function testConnection(array $config): PerformanceCheckResult;
}
