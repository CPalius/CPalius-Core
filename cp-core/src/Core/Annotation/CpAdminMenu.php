<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Bir controller action'ını Studio veya AACP sidebar'ında bir menü
 * öğesine dönüştüren sözleşme.
 *
 * TARGET_METHOD (sınıf değil): bir controller içinde index/create/edit/
 * delete/publish gibi birden çok action bulunabilir, ama genelde bunların
 * yalnızca biri (ör. index()) sidebar'da bir link hak eder. Bu yüzden
 * attribute metot seviyesinde okunur (bkz. AdminMenuRegistrationPass),
 * aynı metottaki #[Route] ile birlikte değerlendirilip route adı/prefix'i
 * çözülür.
 *
 * Menü öğelerinin modül-aktiflik ve yetki filtrelemesi BİLİNÇLİ olarak
 * bu attribute'ta veya derleme-zamanı taramada yapılmaz: active_modules.php
 * çalışma zamanında (AACP üzerinden, cache temizlemeden) değişebildiği
 * için, filtreleme AdminMenuRuntime içinde HER render'da taze yapılır
 * (bkz. AdminMenuRegistrationPass ve AdminMenuRuntime doküman notları).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpAdminMenu
{
    /**
     * @param string $label Sidebar'da gösterilecek metin.
     * @param string $icon symfony/ux-icons ikon adı (ör. "heroicons:home").
     * @param string $panel Hangi kabukta görüneceği: "studio" | "aacp".
     * @param int $priority Küçük değer önce sıralanır (varsayılan 100).
     * @param string|null $capability Bu yeteneğe sahip olmayan kullanıcıya
     *   link gösterilmez. Birden fazla yetenekten EN AZ BİRİ yeterliyse
     *   "|" ile ayrılarak yazılır (ör. "node.post.view.own|node.post.view.any").
     *   null ise sadece kimlik doğrulanmış olmak yeterlidir.
     * @param string|null $group Sidebar'da bu öğenin altına yerleştirileceği
     *   bölüm başlığı (ör. "İçerik", "Sistem"). null ise gruplanmaz.
     * @param string|null $parent Bu öğenin AÇILIR/KAPANIR bir alt menü
     *   olarak yerleştirileceği ÜST öğenin route adı (ör. "aacp_modules").
     *   null ise öğe kendi $group'unun altında ÜST SEVİYE bir link olarak
     *   görünür. Faz 5 AACP menü hiyerarşisi için eklendi (ör. "Modüller"
     *   üst öğesinin altında "Modül Ayarları" alt öğesi) — bkz.
     *   AdminMenuRuntime::render()'ın iki seviyeli ağaç kurma mantığı.
     */
    public function __construct(
        public readonly string $label,
        public readonly string $icon = 'heroicons:squares-2x2',
        public readonly string $panel = 'studio',
        public readonly int $priority = 100,
        public readonly ?string $capability = null,
        public readonly ?string $group = null,
        public readonly ?string $parent = null,
    ) {
    }
}
