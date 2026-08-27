<?php

declare(strict_types=1);

namespace App\Core\Settings\DependencyInjection\Compiler;

use App\Core\Annotation\CpSetting;
use App\Core\Settings\SettingDefinition;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Throwable;

/**
 * SettingsRegistry'yi container derleme zamanında doldurur.
 *
 * ResourceRegistrationPass/AdminMenuRegistrationPass ile aynı iskelet:
 * dosya sistemi taraması + Reflection, Doctrine'den bağımsız, modül
 * izolasyonu try/catch(Throwable) ile sağlanır.
 *
 * Taranan konumlar:
 *   1) cp-core/src/Core/Settings/Definitions — çekirdek ayar taşıyıcıları.
 *   2) Her modülün Settings dizini (varsa) — modül izolasyonu ile.
 *
 * NOT: Bu dizin modül kökünün DOĞRUDAN altındadır ("<module>/Settings"),
 * "<module>/src/Settings" DEĞİL — composer.json'daki PSR-4 haritası
 * ("Modules\\": "cp-content/modules/") ve modüllerin mevcut Controller/,
 * Plugin/, Form/, Twig/ dizin konvansiyonuyla (hiçbiri src/ altında değil)
 * birebir örtüşmesi için bilinçli olarak böyle seçildi.
 *
 * #[CpSetting] REPEATABLE olduğu için bir sınıf üzerinde birden fazla
 * instance bulunabilir; hepsi tek tek toplanır.
 */
final class SettingsRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public const CONTAINER_PARAMETER = 'cpalius.setting_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{key: string, label: string, type: string, default: mixed, variants: array<string, string>, module: string, group: string}> $collected */
        $collected = [];

        $coreSettingsDir = $projectDir.'/cp-core/src/Core/Settings/Definitions';
        foreach ($this->scanDirectory($coreSettingsDir, 'App\\Core\\Settings\\Definitions\\', $container) as $setting) {
            $collected[] = $setting;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleSettingsDir = rtrim((string) $bundleMeta['path'], '/').'/Settings';
            $moduleSettingsNamespace = $bundleMeta['namespace'].'\\Settings\\';

            try {
                foreach ($this->scanDirectory($moduleSettingsDir, $moduleSettingsNamespace, $container) as $setting) {
                    $collected[] = $setting;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün Settings dizini taranırken
                // hata oluşursa sadece o modülün ayarları kayıt olmaz.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition('App\Core\Settings\SettingsRegistry')) {
            $definition = $container->getDefinition('App\Core\Settings\SettingsRegistry');

            foreach ($collected as $setting) {
                $definition->addMethodCall('addDefinition', [new Definition(SettingDefinition::class, [
                    $setting['key'],
                    $setting['label'],
                    $setting['type'],
                    $setting['default'],
                    $setting['variants'],
                    $setting['module'],
                    $setting['group'],
                ])]);
            }
        }
    }

    /**
     * @return list<array{key: string, label: string, type: string, default: mixed, variants: array<string, string>, module: string, group: string}>
     */
    private function scanDirectory(string $dir, string $namespacePrefix, ContainerBuilder $container): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($dir, '/\.php$/'));

        $collected = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $relativePath = ltrim(substr((string) $file->getPathname(), strlen($dir)), '/\\');
            $className = $namespacePrefix.str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

            try {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                foreach ($reflection->getAttributes(CpSetting::class) as $attribute) {
                    /** @var CpSetting $setting */
                    $setting = $attribute->newInstance();

                    $collected[] = [
                        'key' => $setting->key,
                        'label' => $setting->label,
                        'type' => $setting->type,
                        'default' => $setting->default,
                        'variants' => $setting->variants,
                        'module' => $setting->module,
                        'group' => $setting->group,
                    ];
                }
            } catch (Throwable) {
                // Tek bir dosyanın reflection'ı başarısız olursa (namespace
                // uyuşmazlığı, eksik parent class vb.) o dosya atlanır;
                // tüm tarama iptal edilmez.
                continue;
            }
        }

        return $collected;
    }
}
