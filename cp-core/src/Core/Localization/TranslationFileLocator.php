<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * Sistemdeki TÜM "messages+intl-icu.{locale}.yaml" çeviri dosyalarının
 * konumunu çözer: çekirdek (cp-content/translations) + her modülün kendi
 * Resources/translations dizini (bkz. cp-core/config/packages/translation.yaml
 * dokümanı — modüller Symfony bundle metadata'sı üzerinden otomatik taranır,
 * bu locator o taramayı dosya sistemi seviyesinde manuel tekrar eder çünkü
 * Dil Yönetimi paneli hangi dosyaya YAZACAĞINI bilmek zorundadır, sadece
 * "hangi anahtar hangi değere çözülüyor" bilgisini değil).
 *
 * Fail-Safe: modules dizini okunamazsa boş liste döner, tek bir bozuk
 * modül dizini diğerlerinin taranmasını engellemez.
 */
final class TranslationFileLocator
{
    private const DOMAIN = 'messages+intl-icu';

    public function __construct(
        private readonly string $coreTranslationsDir,
        private readonly string $modulesDir,
        private readonly array $locales = ['tr', 'en'],
    ) {
    }

    /**
     * @return array<string, array<string, string>> domain-etiketi => [locale => mutlak dosya yolu]
     *   domain-etiketi "core" ya da "module:<ModuleName>" biçimindedir.
     */
    public function locateAll(): array
    {
        $groups = [
            'core' => $this->locateGroup($this->coreTranslationsDir),
        ];

        foreach ($this->discoverModuleTranslationDirs() as $moduleName => $dir) {
            $group = $this->locateGroup($dir);

            if ($group !== []) {
                $groups['module:'.$moduleName] = $group;
            }
        }

        return array_filter($groups, static fn (array $group): bool => $group !== []);
    }

    /**
     * @return array<string, string> locale => mutlak dosya yolu (dosya yoksa o locale için girdi olmaz)
     */
    private function locateGroup(string $dir): array
    {
        $result = [];

        foreach ($this->locales as $locale) {
            $path = rtrim($dir, '/\\').'/'.self::DOMAIN.'.'.$locale.'.yaml';

            if (is_file($path)) {
                $result[$locale] = $path;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string> ModuleName => Resources/translations mutlak yolu
     */
    private function discoverModuleTranslationDirs(): array
    {
        if (!is_dir($this->modulesDir)) {
            return [];
        }

        $dirs = [];

        foreach (glob(rtrim($this->modulesDir, '/\\').'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $translationsDir = $moduleDir.'/Resources/translations';

            if (is_dir($translationsDir)) {
                $dirs[basename($moduleDir)] = $translationsDir;
            }
        }

        ksort($dirs);

        return $dirs;
    }

    /**
     * Yeni bir anahtar eklerken hangi dosyaya yazılacağını belirlemek için
     * kullanılır: domain-etiketi zaten biliniyorsa (mevcut anahtar
     * düzenleniyorsa) locateAll() sonucundan doğrudan okunur; YENİ bir
     * anahtar için varsayılan olarak "core" grubunun dosya yolu üretilir
     * (dosya yoksa bile, çünkü Dil Yönetimi paneli onu ilk kez oluşturabilir).
     */
    public function resolveCoreFilePath(string $locale): string
    {
        return rtrim($this->coreTranslationsDir, '/\\').'/'.self::DOMAIN.'.'.$locale.'.yaml';
    }
}
