<?php

declare(strict_types=1);

namespace Modules\BrokenBoot;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * KASITLI OLARAK BOZUK kullanıcı-alanı modülü — Manifesto Law 2.3
 * ("Safe Mode & Recovery Console") için canlı kanıt üretir.
 *
 * ── Neden bu senaryo, sözdizimi hatasından daha önemli ───────────────────
 *
 * Bozuk bir dosya (parse error) autoloader tarafından yüklenemez ve
 * ModuleRegistry::validate() onu daha container derlenmeden eler — bu,
 * ayrıca test edilen ama KOLAY olan senaryodur.
 *
 * Burada test edilen senaryo sinsi olanıdır: modül sözdizimsel olarak
 * kusursuzdur, autoloader onu sorunsuz yükler, BundleInterface
 * sözleşmesini eksiksiz uygular, lint:container ve lint:yaml temiz geçer
 * — ve yalnızca ÇALIŞMA ANINDA, Kernel::boot() içinde patlar.
 *
 * Üretimde gerçekten olan budur: eksik bir env değişkeni, erişilemeyen
 * bir dış servis, bir kütüphanenin sürüm uyumsuzluğu. Hiçbir statik
 * kontrol bunu yakalayamaz. Yakalayabilecek tek şey Kernel::boot()
 * içindeki izolasyon zırhıdır — ve bu fixture, o zırhın gerçekten
 * çalıştığını kanıtlamak için vardır.
 *
 * ── Namespace neden kritik ───────────────────────────────────────────────
 *
 * Kernel::boot(), bir bundle'ın hatasını yutup yutmayacağına sınıf adının
 * "Modules\" ile başlayıp başlamadığına bakarak karar verir
 * (Kernel::MODULE_NAMESPACE_PREFIX). Bu sınıf o ön eki TAŞIR, dolayısıyla
 * izolasyon devreye girer.
 *
 * Fiziksel olarak cp-content/modules/ altında DEĞİL, cp-core/tests/
 * Fixtures/Modules/ altında yaşar: composer.json'daki autoload-dev,
 * "Modules\" ön ekine bu ikinci dizini ekler. Böylece fixture yalnızca
 * dev/test autoloader'ında görünür, üretim autoloader'ında hiç var olmaz
 * ve cp:module:list gibi komutların taradığı gerçek modül dizini
 * kirlenmez.
 */
final class BrokenBootModule extends Bundle
{
    /**
     * Test, karantina logunda TAM OLARAK bu metni arar. Sabit olarak
     * tanımlanmıştır ki mesaj değişirse test de derleme zamanında
     * güncellensin — logda "bir şeyler yazılmış" demek yetmez, DOĞRU
     * sebebin yazıldığı doğrulanmalıdır.
     */
    public const FAILURE_MESSAGE = 'BrokenBootModule kasitli olarak boot() sirasinda patladi.';

    public function boot(): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}
