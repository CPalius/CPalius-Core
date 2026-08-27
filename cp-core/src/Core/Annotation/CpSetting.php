<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Cotonti tarzı bir "ayar" (setting) tanımını derleme-zamanı taramasına
 * bağlayan sözleşme. Ayarların doğal bir "entity"si olmadığı için (bir
 * Node veya Resource gibi tek bir sınıfa ait değildirler), bu attribute
 * REPEATABLE'dır ve gövdesi boş, salt attribute-taşıyıcı bir sınıf üzerine
 * yığılır (bkz. App\Core\Settings\Definitions\CoreSettings örneği).
 *
 * Konvansiyon (Entity/ dizininin #[CpResource] için sabit konum olması
 * gibi): çekirdek ayar taşıyıcıları App\Core\Settings\Definitions\* altında,
 * modül ayar taşıyıcıları <module>/Settings/* altında (namespace
 * Modules\X\Settings\) yaşar — bkz. SettingsRegistrationPass.
 *
 * Değer ayrımı: bu attribute sadece TANIMI (key, label, type, default,
 * variants) derleme zamanında sabitler. Gerçek DEĞER veritabanında
 * (cp_settings tablosu) saklanır ve SettingsRegistry tarafından runtime'da
 * lazy olarak okunur — bkz. SettingsRegistry docblock'u.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class CpSetting
{
    /**
     * @param string $key Ayarın benzersiz kimliği (ör. "core.site_name").
     * @param string $label Yönetim ekranında gösterilecek etiket.
     * @param string $type 'text' | 'checkbox' | 'select' | 'textarea' | 'integer'.
     * @param mixed $default Veritabanında hiç kayıt yoksa dönecek değer.
     * @param array<string, string> $variants 'select' tipi için seçenekler
     *   (değer => görünen etiket).
     * @param string $module Bu ayarı tanımlayan modülün veya eklentinin
     *   kimliği, çekirdek ayarlar için "core". AACP'nin "Modül Ayarları" /
     *   "Eklenti Ayarları" ayrımı bu alanın PluginRegistry'de kayıtlı bir
     *   eklenti adıyla (PluginInterface::getName()) eşleşip eşleşmediğine
     *   bakarak yapılır (bkz. AACPController::settingsModules()/settingsPlugins()) —
     *   bu yüzden bir eklentiye ait ayar tanımlarken buraya modülün değil,
     *   doğrudan eklentinin getName() değerini yazın (ör. "blog_widget").
     * @param string $group Yönetim ekranında bu ayarın gruplanacağı bölüm.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly mixed $default = null,
        public readonly array $variants = [],
        public readonly string $module = 'core',
        public readonly string $group = 'general',
    ) {
    }
}
