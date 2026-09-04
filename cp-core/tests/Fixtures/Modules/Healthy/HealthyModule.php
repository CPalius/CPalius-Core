<?php

declare(strict_types=1);

namespace Modules\Healthy;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * SAĞLAM kullanıcı-alanı modülü — izolasyon testinin KONTROL GRUBU.
 *
 * Bir izolasyon testi tek başına "bozuk modül elendi" demeyi kanıtlarsa
 * eksiktir: aynı sonuç, sistemin TÜM modülleri elemesiyle de elde
 * edilirdi. Yani "bozuk olan atıldı" iddiası ancak "sağlam olan
 * ATILMADI" iddiasıyla birlikte anlam taşır.
 *
 * Bu modül o ikinci yarıyı sağlar: ModuleRegistry::getHealthyModuleBundles()
 * onu listede TUTMALI, Kernel::boot() onu normal şekilde boot ETMELİ ve
 * karantina logunda adı GEÇMEMELİDİR — hem de bozuk kardeşiyle aynı
 * çalıştırmada.
 *
 * boot() çağrıldığını gözlemleyebilmek için bir sayaç tutulur; test bu
 * sayacın arttığını doğrulayarak modülün gerçekten boot edildiğini
 * (yalnızca "hata vermediğini" değil) kanıtlar.
 */
final class HealthyModule extends Bundle
{
    /**
     * Statik sayaç kullanılır çünkü Kernel, bundle nesnelerini kendi
     * içinde üretir; test o örneğe erişemez. Her testin temiz bir sayfayla
     * başlaması için resetBootCount() setUp içinde çağrılır.
     */
    private static int $bootCount = 0;

    public function boot(): void
    {
        parent::boot();

        ++self::$bootCount;
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    public static function resetBootCount(): void
    {
        self::$bootCount = 0;
    }
}
