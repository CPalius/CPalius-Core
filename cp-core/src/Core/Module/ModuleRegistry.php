<?php

namespace App\Core\Module;

use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Throwable;

/**
 * Aktif modül listesini karantina kurallarıyla birlikte çözer.
 *
 * Bu sınıf, DI container henüz boot olmadan (config/bundles.php aşamasında)
 * çağrılabilecek şekilde tasarlanmıştır: hiçbir bağımlılığı yoktur, sadece
 * dosya sistemi ve autoloader ile konuşur. Container boot olduktan sonra
 * aynı sınıf bir servis olarak da kullanılabilir (ör. admin panelinde
 * karantina uyarılarını göstermek için).
 */
final class ModuleRegistry
{
    /** @var array<int, array{class: string, reason: string}> */
    private array $quarantined = [];

    public function __construct(
        private readonly string $activeModulesFile,
        private readonly string $quarantineLogFile,
        private readonly ?string $modulesDir = null,
    ) {
    }

    /**
     * config/active_modules.php dosyasını okur, her modül sınıfını
     * doğrular; bozuk olanları karantinaya alıp listeden çıkarır.
     *
     * @return list<class-string<BundleInterface>>
     */
    public function getHealthyModuleBundles(): array
    {
        $this->quarantined = [];

        $declaredModules = $this->loadDeclaredModules();

        $healthy = [];
        foreach ($declaredModules as $moduleClass) {
            if (!is_string($moduleClass) || $moduleClass === '') {
                $this->quarantine('(geçersiz girdi)', 'active_modules.php içinde boş veya geçersiz bir modül tanımı bulundu.');
                continue;
            }

            $reason = $this->validate($moduleClass);
            if ($reason !== null) {
                $this->quarantine($moduleClass, $reason);
                continue;
            }

            $healthy[] = $moduleClass;
        }

        if ($this->quarantined !== []) {
            $this->flushQuarantineLog();
        }

        return $healthy;
    }

    /**
     * @return list<array{class: string, reason: string}>
     */
    public function getQuarantinedModules(): array
    {
        return $this->quarantined;
    }

    /**
     * Bir modülü DIŞARIDAN (ör. cp:module:activate dry-run başarısız
     * olduğunda) kalıcı olarak karantina logına yazar. getHealthyModuleBundles()
     * akışından bağımsızdır: burada modül active_modules.php'ye hiç
     * yazılmamıştır, sadece "bu modülü aktive etmeyi deneme, denendi ve
     * başarısız oldu" kaydı düşülür.
     */
    public function quarantinePermanently(string $moduleClass, string $reason): void
    {
        $dir = \dirname($this->quarantineLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s modülü aktivasyon ön kontrolünde (dry-run) başarısız olduğu için karantinaya alındı. Sebep: %s',
            date('Y-m-d H:i:s'),
            $moduleClass,
            $reason,
        );

        @file_put_contents($this->quarantineLogFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * cp-content/modules/ altındaki TÜM modül klasörlerini (aktif olsun
     * olmasın) tarar; her biri için module.json'dan ad/versiyon bilgisini,
     * active_modules.php + validate() sonucuna göre de durumunu üretir.
     *
     * cp:module:list gibi tanısal komutlar için tasarlanmıştır;
     * getHealthyModuleBundles()'ın aksine burada hiçbir şey karantina
     * listesine yazılmaz, sadece durum raporlanır.
     *
     * @return list<array{
     *     dirName: string,
     *     name: string,
     *     version: string,
     *     class: ?string,
     *     status: 'active'|'inactive'|'quarantined',
     *     reason: ?string,
     * }>
     */
    public function discoverAllModules(): array
    {
        if ($this->modulesDir === null || !is_dir($this->modulesDir)) {
            return [];
        }

        $declaredModules = $this->loadDeclaredModules();

        $modules = [];
        foreach (scandir($this->modulesDir) ?: [] as $dirName) {
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }

            $moduleDir = $this->modulesDir.'/'.$dirName;
            if (!is_dir($moduleDir)) {
                continue;
            }

            $modules[] = $this->describeModule($dirName, $moduleDir, $declaredModules);
        }

        return $modules;
    }

    /**
     * @param list<mixed> $declaredModules
     * @return array{dirName: string, name: string, version: string, class: ?string, status: 'active'|'inactive'|'quarantined', reason: ?string}
     */
    private function describeModule(string $dirName, string $moduleDir, array $declaredModules): array
    {
        $manifest = $this->readManifest($moduleDir);
        $name = $manifest['name'] ?? $dirName;
        $version = $manifest['version'] ?? 'unknown';
        $moduleClass = $manifest['bundle'] ?? null;

        if (!is_string($moduleClass) || $moduleClass === '') {
            return [
                'dirName' => $dirName,
                'name' => $name,
                'version' => $version,
                'class' => null,
                'status' => 'quarantined',
                'reason' => 'module.json içinde geçerli bir "bundle" alanı bulunamadı.',
            ];
        }

        $isDeclared = in_array($moduleClass, $declaredModules, true);
        $validationError = $this->validate($moduleClass);

        if ($validationError !== null) {
            return [
                'dirName' => $dirName,
                'name' => $name,
                'version' => $version,
                'class' => $moduleClass,
                'status' => 'quarantined',
                'reason' => $validationError,
            ];
        }

        return [
            'dirName' => $dirName,
            'name' => $name,
            'version' => $version,
            'class' => $moduleClass,
            'status' => $isDeclared ? 'active' : 'inactive',
            'reason' => null,
        ];
    }

    /**
     * @return array{name?: string, version?: string, bundle?: string}
     */
    private function readManifest(string $moduleDir): array
    {
        $manifestFile = $moduleDir.'/module.json';
        if (!is_file($manifestFile)) {
            return [];
        }

        try {
            $contents = file_get_contents($manifestFile);
            $data = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<mixed>
     */
    private function loadDeclaredModules(): array
    {
        if (!is_file($this->activeModulesFile)) {
            return [];
        }

        try {
            $modules = require $this->activeModulesFile;
        } catch (Throwable $e) {
            // active_modules.php dosyasının kendisi bile syntax hatası
            // içerebilir; bu durumda tüm modül sistemi devre dışı kalır
            // ama Core hâlâ ayağa kalkar.
            $this->quarantine('(active_modules.php)', 'Dosya okunamadı: '.$e->getMessage());

            return [];
        }

        return is_array($modules) ? array_values($modules) : [];
    }

    /**
     * Modül sınıfını doğrular. Sorun yoksa null, varsa sebep metni döner.
     */
    private function validate(string $moduleClass): ?string
    {
        // 1) Autoloader sınıfı fiziksel olarak bulup yükleyebiliyor mu?
        //    (Bir syntax hatası burada Throwable olarak fırlar; class_exists
        //    otomatik require tetikler.)
        try {
            $exists = class_exists($moduleClass);
        } catch (Throwable $e) {
            return sprintf('Sınıf yüklenirken hata oluştu: %s', $e->getMessage());
        }

        if (!$exists) {
            return 'Sınıf bulunamadı (dosya eksik olabilir veya namespace/dosya adı uyuşmuyor).';
        }

        // 2) Sözleşmeye uyuyor mu? (Her Module aynı zamanda bir Bundle olmalı.)
        try {
            $implementsBundle = is_subclass_of($moduleClass, BundleInterface::class)
                || in_array(BundleInterface::class, class_implements($moduleClass) ?: [], true);
        } catch (Throwable $e) {
            return sprintf('Sınıf denetlenirken hata oluştu: %s', $e->getMessage());
        }

        if (!$implementsBundle) {
            return sprintf('%s sınıfı BundleInterface uygulamıyor.', $moduleClass);
        }

        return null;
    }

    private function quarantine(string $moduleClass, string $reason): void
    {
        $this->quarantined[] = [
            'class' => $moduleClass,
            'reason' => $reason,
        ];
    }

    private function flushQuarantineLog(): void
    {
        $dir = \dirname($this->quarantineLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lines = [];
        foreach ($this->quarantined as $entry) {
            $lines[] = sprintf(
                '[%s] %s modülü karantinaya alındı. Sebep: %s',
                date('Y-m-d H:i:s'),
                $entry['class'],
                $entry['reason'],
            );
        }

        @file_put_contents(
            $this->quarantineLogFile,
            implode(PHP_EOL, $lines).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
