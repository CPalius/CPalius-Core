<?php

declare(strict_types=1);

namespace Modules\NotABundle;

/**
 * SÖZLEŞMEYİ İHLAL EDEN modül — Manifesto Law 2.2 ("Compile-Time
 * Protection") için kanıt üretir.
 *
 * Sınıf var, autoload edilebilir, sözdizimi kusursuz — ama
 * BundleInterface'i UYGULAMIYOR. Bu, active_modules.php'yi elle
 * düzenleyen ya da module.json'ında yanlış bir "bundle" değeri olan bir
 * geliştiricinin ürettiği tipik hatadır.
 *
 * Kernel bu sınıfı "new $moduleClass()" ile örnekleyip getBundles()
 * listesine koysaydı, Symfony'nin bundle yaşam döngüsü ilk
 * setContainer() çağrısında ölümcül bir TypeError ile çökerdi — üstelik
 * izolasyon zırhının İÇİNDE değil, ONDAN ÖNCE, yani "Core Never Dies"
 * garantisinin tamamen dışında.
 *
 * ModuleRegistry::validate() bu yüzden sınıfın yalnızca VAR olduğunu
 * değil, sözleşmeye UYDUĞUNU da doğrular ve uymayanı karantinaya alır.
 * Bu fixture o kontrolün gerçekten çalıştığını kanıtlar.
 */
final class NotABundleModule
{
    public function boot(): void
    {
        // Kasıtlı olarak boş: bu sınıfın sorunu ne yaptığı değil, NE
        // OLMADIĞIDIR (bir Bundle değil).
    }
}
