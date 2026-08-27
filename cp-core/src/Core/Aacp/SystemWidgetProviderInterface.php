<?php

declare(strict_types=1);

namespace App\Core\Aacp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * AACP Sistem Monitörü'ne (/aacp/system) modüllerin kendi durum
 * kartlarını eklemesini sağlayan genişletme noktası.
 *
 * Manifesto Law 2.1/2.3 (Core Never Dies) gereği AACPController bir
 * ÇEKİRDEK dosyasıdır ve hiçbir modüle (Blog, Portfolio vb.) doğrudan
 * bağımlı OLAMAZ — modül devre dışı bırakıldığında veya karantinaya
 * alındığında AACP'nin çökmeden ayakta kalması gerekir. Bu yüzden
 * AACPController tek tek modül servislerini import etmez; bunun yerine
 * bu arayüzü implemente eden TÜM servisleri (#[AutoconfigureTag] ile
 * otomatik etiketlenmiş, App\Core\Aacp\SystemWidgetProviderInterface
 * $iterable olarak enjekte edilen) tagged_iterator üzerinden toplar.
 *
 * Bir modül devre dışıysa onun provider'ı zaten container'a hiç
 * kaydedilmez (SafeModuleRouteLoader/active_modules.php ile aynı
 * izolasyon ilkesi) — AACP geri kalan tüm widget'ları normal şekilde
 * göstermeye devam eder.
 *
 * Kullanım (modül tarafında):
 *   final class ScheduledPostsWidgetProvider implements SystemWidgetProviderInterface { ... }
 * Ekstra services.yaml tag'i GEREKMEZ — #[AutoconfigureTag] + autoconfigure:true
 * (App\: ve Modules\Blog\: resource taramaları) otomatik olarak yeterlidir.
 */
#[AutoconfigureTag('cpalius.aacp.system_widget_provider')]
interface SystemWidgetProviderInterface
{
    /**
     * Bu provider'ın ürettiği kartı döner. Provider'ın kendisi hata
     * fırlatırsa (ör. bir tablo henüz migrate edilmemişse) AACPController
     * bunu yakalayıp o widget'ı sessizce atlar — tek bir bozuk provider
     * tüm Sistem Monitörü sayfasını çökertmemelidir (fail-safe).
     */
    public function getWidget(): SystemWidgetData;
}
