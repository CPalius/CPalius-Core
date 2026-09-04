<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\BrokenModule;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * KASITLI OLARAK BOZUK test modülü — Manifesto Law 2.2 ve 2.3'ün canlı
 * kanıtı olan ModuleIsolationTest tarafından kullanılır.
 *
 * ── Neden gerçek bir "syntax error" değil ────────────────────────────────
 *
 * Bir sözdizimi hatası PHP tarafından DERLEME anında yakalanır ve dosyayı
 * autoload edilemez hâle getirir; bu, ModuleRegistry::validate()'in
 * class_exists() kolunun test ettiği ayrı bir senaryodur (bkz.
 * ModuleRegistryTest). Buradaki senaryo daha sinsi olanıdır: modül
 * SÖZDİZİMSEL OLARAK GEÇERLİDİR, autoloader onu sorunsuz yükler,
 * BundleInterface sözleşmesini eksiksiz uygular — ve yalnızca ÇALIŞMA
 * ANINDA, boot() çağrıldığında patlar.
 *
 * Bu, üretimde gerçekten olan senaryodur: eksik bir env değişkeni,
 * erişilemeyen bir servis, üçüncü parti bir kütüphanenin sürüm
 * uyumsuzluğu. Hiçbir statik kontrol (lint:container, lint:yaml, PHPStan)
 * bunu yakalayamaz; yalnızca Kernel::boot() içindeki izolasyon zırhı
 * yakalayabilir.
 *
 * ── Namespace notu ───────────────────────────────────────────────────────
 *
 * Kernel::boot(), bir bundle'ı "modül" saymak için sınıf adının
 * Kernel::MODULE_NAMESPACE_PREFIX ("Modules\") ile başlamasına bakar.
 * Bu fixture "App\Tests\Fixtures\..." altındadır, yani o kontrolden
 * GEÇMEZ — bu bilinçlidir: ModuleIsolationTest, izolasyonun namespace
 * sınırına saygı gösterdiğini de doğrular (çekirdek bundle hataları
 * YUTULMAMALIDIR). Gerçek modül davranışı için, aynı dizindeki
 * BrokenUserSpaceModule kullanılır.
 */
final class BrokenModule extends Bundle
{
    public const FAILURE_MESSAGE = 'BrokenModule kasitli olarak boot() sirasinda patladi.';

    public function boot(): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}
