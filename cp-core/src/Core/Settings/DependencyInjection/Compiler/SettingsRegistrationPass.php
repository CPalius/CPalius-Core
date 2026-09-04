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
 * Fills SettingsRegistry at compile time by scanning core and module setting carriers.
 * Filesystem + reflection only, with per-module isolation; Doctrine is never touched.
 */
final class SettingsRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public const CONTAINER_PARAMETER = 'cpalius.setting_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{key: string, label: string, type: string, default: mixed, variants: array<string, string>, module: string, group: string, translatable: bool, scope: ?string}> $collected */
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

            // Convention: "<module>/Settings", not "<module>/src/Settings", matching the
            // PSR-4 map in composer.json and the other module directories.
            $moduleSettingsDir = rtrim((string) $bundleMeta['path'], '/').'/Settings';
            $moduleSettingsNamespace = $bundleMeta['namespace'].'\\Settings\\';

            try {
                foreach ($this->scanDirectory($moduleSettingsDir, $moduleSettingsNamespace, $container) as $setting) {
                    $collected[] = $setting;
                }
            } catch (Throwable) {
                // Module isolation: one broken Settings directory must not abort the scan.
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
                    $setting['translatable'],
                    $setting['scope'],
                ])]);
            }
        }
    }

    /**
     * @return list<array{key: string, label: string, type: string, default: mixed, variants: array<string, string>, module: string, group: string, translatable: bool, scope: ?string}>
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
            $relativePath = ltrim(substr((string) $file->getPathname(), \strlen($dir)), '/\\');
            $className = $namespacePrefix.str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

            try {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                // #[CpSetting] is repeatable, so one carrier class may hold many.
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
                        'translatable' => $setting->translatable,
                        'scope' => $setting->scope,
                    ];
                }
            } catch (Throwable) {
                // A single unreadable file is skipped; the whole scan is not aborted.
                continue;
            }
        }

        return $collected;
    }
}
