<?php

namespace App\Core\Module;

/**
 * config/active_modules.php dosyasını programatik olarak, güvenli ve
 * öngörülebilir bir formatta yeniden yazar.
 *
 * Elle string concatenation yerine var_export() kullanılır; böylece
 * dosya içeriği her zaman geçerli PHP kalır (tırnak/escape hataları
 * oluşamaz). var_export()'un ürettiği ham 'Modules\\Blog\\BlogModule'
 * string'i, okunabilirlik ve statik analiz için Modules\Blog\BlogModule::class
 * biçimine dönüştürülür.
 */
final class ActiveModulesFileWriter
{
    public function __construct(
        private readonly string $activeModulesFile,
    ) {
    }

    /**
     * @return list<class-string>
     */
    public function read(): array
    {
        if (!is_file($this->activeModulesFile)) {
            return [];
        }

        $modules = require $this->activeModulesFile;

        return is_array($modules) ? array_values($modules) : [];
    }

    /**
     * @param class-string $moduleClass
     */
    public function add(string $moduleClass): void
    {
        $modules = $this->read();

        if (in_array($moduleClass, $modules, true)) {
            return;
        }

        $modules[] = $moduleClass;
        $this->write($modules);
    }

    /**
     * @param class-string $moduleClass
     */
    public function remove(string $moduleClass): void
    {
        $modules = array_values(array_filter(
            $this->read(),
            static fn (string $existing) => $existing !== $moduleClass,
        ));

        $this->write($modules);
    }

    /**
     * Dosyanın tüm içeriğini verilen listeyle değiştirir. Dry-run
     * senaryolarında (geçici bir modül eklenip test edildikten sonra
     * eski hale geri dönmek için) kullanılır.
     *
     * @param list<class-string> $modules
     */
    public function replaceAll(array $modules): void
    {
        $this->write($modules);
    }

    /**
     * @param list<class-string> $modules
     */
    private function write(array $modules): void
    {
        $exported = var_export($modules, true);

        // var_export() eski "array (...)" sözdizimini kullanır; modern
        // kısa dizi sözdizimine ([...]) çeviriyoruz.
        $exported = preg_replace('/^array \(/', '[', $exported);
        $exported = preg_replace('/\)$/', ']', $exported);
        $exported = preg_replace('/^(\s*)\d+ => /m', '$1', $exported);

        // var_export() her sınıf adını 'Modules\\Blog\\BlogModule' olarak
        // yazar; bunu Modules\Blog\BlogModule::class biçimine çeviriyoruz.
        $exported = preg_replace_callback(
            "/'((?:[A-Za-z0-9_]+\\\\\\\\)+[A-Za-z0-9_]+)'/",
            static fn (array $m) => str_replace('\\\\', '\\', $m[1]).'::class',
            $exported,
        );

        $contents = <<<PHP
        <?php

        // Aktif modüllerin listesi. Bu dosya statiktir; container henüz boot
        // olmadan (bundles.php aşamasında) okunur, bu yüzden DB'ye bağımlı değildir.
        // Bu dosya cp:module:activate / cp:module:deactivate komutları tarafından
        // otomatik olarak güncellenir; elle düzenlenebilir ama format bozulmamalıdır.

        return {$exported};

        PHP;

        $dir = \dirname($this->activeModulesFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Atomik yazma: önce geçici dosyaya yaz, sonra rename et. Bu sayede
        // yazma sırasında bir kesinti olsa bile active_modules.php ya eski
        // ya da tamamen yeni haliyle kalır, asla yarım/bozuk kalmaz.
        $tmpFile = $this->activeModulesFile.'.'.uniqid('tmp_', true);
        file_put_contents($tmpFile, $contents, LOCK_EX);
        rename($tmpFile, $this->activeModulesFile);
    }
}
