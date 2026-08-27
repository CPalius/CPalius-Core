<?php

declare(strict_types=1);

namespace App\Core\Hook\Attribute;

/**
 * Attribute Kulvarı (Symfony Tarzı): bir servis metodunu belirli bir
 * $hookPoint'e bağlayan sözleşme. Flat-File Kulvarı'nın (cp-content/modules/*\/Hooks/{hook_point}.php)
 * DI konteynerine ihtiyaç duyan, servis bağımlılığı olan (EntityManager,
 * Logger, başka bir servis...) dinleyiciler için alternatifidir.
 *
 * REPEATABLE: aynı metot birden fazla hook noktasına bağlanabilir
 * (#[CpHook] yığını CpSetting ile aynı gerekçeyle tekrarlanabilir kılındı).
 * TARGET_METHOD: hook noktasına bağlanan her zaman TEK bir metottur, tüm
 * sınıf değil — HookManager bu metodu doğrudan çağırır.
 *
 * Kullanım (modül tarafında):
 *   final class BlogSidebarHooks
 *   {
 *       #[CpHook('tema.render.sidebar')]
 *       public function onSidebarRender(HookContext $context): HookContext { ... }
 *   }
 * Ekstra services.yaml tag'i GEREKMEZ — HookRegistrationPass derleme
 * zamanında bu attribute'u taşıyan tüm servis metotlarını toplar.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class CpHook
{
    /**
     * @param string $hookPoint Kanca noktasının benzersiz adı (ör. "tema.render.sidebar").
     * @param int $priority Küçük değer önce çalışır (varsayılan 100) — Flat-File
     *   kulvarındaki dosyaların çalışma sırasıyla KARIŞTIRILMAZ: iki kulvar
     *   birbirinden bağımsız sırayla yürütülür (bkz. HookManager::trigger()).
     */
    public function __construct(
        public readonly string $hookPoint,
        public readonly int $priority = 100,
    ) {
    }
}
