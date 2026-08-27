<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * "Modül Eklentileri" (Module Plugins) mimarisinin uzantı noktası —
 * SystemWidgetProviderInterface (cp-core/src/Core/Aacp/) ile aynı
 * felsefe, ama AACP'nin dar "sistem sağlık kartı" kapsamının aksine
 * herhangi bir modülün Twig'den `{{ cp_plugin('isim') }}` ile
 * çağırabileceği genel amaçlı bir render bloğu üretir.
 *
 * Manifesto Law 2.1/2.3 (Core Never Dies) gereği çekirdek (PluginRegistry,
 * PluginRuntime) hiçbir modülün Plugin sınıfını doğrudan import ETMEZ;
 * bunun yerine bu arayüzü implemente eden TÜM servisleri (#[AutoconfigureTag]
 * ile otomatik etiketlenmiş, TaggedIterator üzerinden enjekte edilen)
 * toplar. Bir modül devre dışıysa/karantinadaysa onun services.yaml'ı
 * hiç yüklenmez, dolayısıyla o modülün Plugin'leri PluginRegistry'ye
 * hiç kaydolmaz — ekstra bir "aktif modül" kontrolüne gerek kalmaz.
 *
 * Kullanım (modül tarafında):
 *   final class BlogWidgetPlugin implements PluginInterface { ... }
 * Ekstra services.yaml tag'i GEREKMEZ — #[AutoconfigureTag] + autoconfigure:true
 * (modülün kendi Plugin/ dizinini tarayan resource girdisi) otomatik
 * olarak yeterlidir.
 */
#[AutoconfigureTag('cpalius.module_plugin')]
interface PluginInterface
{
    /**
     * PluginRegistry içinde ve {{ cp_plugin('isim') }} çağrısında
     * kullanılan benzersiz kısa kimlik (ör. 'blog_widget').
     */
    public function getName(): string;

    /**
     * İnsan tarafından okunabilir etiket (ör. AACP "Modül Eklentileri"
     * listesinde gösterilecek başlık — Faz 4).
     */
    public function getLabel(): string;

    /**
     * Bu eklentinin ürettiği HTML parçasını döner. $context, çağıran
     * Twig şablonundan {{ cp_plugin('isim', {locale: ...}) }} ile
     * serbestçe geçirilen anahtar-değer çiftleridir; her plugin kendi
     * beklediği anahtarları belgelemeli ve eksik/geçersiz context'te
     * makul bir varsayılana düşmelidir (fail-safe).
     *
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string;

    /**
     * false dönerse PluginRuntime bu eklentiyi hiç render ETMEZ (sessizce
     * boş string döner) — Faz 4'te AACP'den aktif/pasif edilebilecek
     * eklentiler için genişletme noktası. Şimdilik (Faz 3) somut plugin
     * sınıfları burada sabit true döner; DB/#[CpSetting] tabanlı gerçek
     * bir "etkin eklentiler" listesi Faz 4'te eklenecek.
     */
    public function isActive(): bool;
}
