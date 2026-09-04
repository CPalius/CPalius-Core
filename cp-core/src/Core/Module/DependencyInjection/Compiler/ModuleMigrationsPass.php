<?php

declare(strict_types=1);

namespace App\Core\Module\DependencyInjection\Compiler;

use App\Core\Module\ModuleManifest;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Registers every ACTIVE module's Resources/migrations directory with Doctrine Migrations.
 * Uses the bundle's own extension point (Configuration::addMigrationsDirectory).
 */
final class ModuleMigrationsPass implements CompilerPassInterface
{
    public const MIGRATIONS_DIR = 'Resources/migrations';

    /** Namespace prefix; migrations are loaded by path, never autoloaded. */
    private const NAMESPACE_PREFIX = 'ModuleMigrations';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('doctrine.migrations.configuration')) {
            return;
        }

        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $modulesDir = $projectDir.'/cp-content/modules';

        if (!is_dir($modulesDir)) {
            return;
        }

        $definition = $container->getDefinition('doctrine.migrations.configuration');

        // Only bundles the kernel actually booted are considered: an inactive module
        // must not have its schema applied by a stray migrate run.
        foreach ($this->activeModuleDirs($container, $modulesDir) as $dirName) {
            $migrationsDir = $modulesDir.'/'.$dirName.'/'.self::MIGRATIONS_DIR;

            if (!is_dir($migrationsDir)) {
                continue;
            }

            $container->addResource(new DirectoryResource($migrationsDir, '/\.php$/'));
            $definition->addMethodCall('addMigrationsDirectory', [
                self::NAMESPACE_PREFIX.'\\'.$dirName,
                $migrationsDir,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function activeModuleDirs(ContainerBuilder $container, string $modulesDir): array
    {
        if (!$container->hasParameter('kernel.bundles_metadata')) {
            return [];
        }

        /** @var array<string, array{path: string, namespace: string}> $bundles */
        $bundles = $container->getParameter('kernel.bundles_metadata');
        $dirs = [];

        foreach ($bundles as $meta) {
            $path = rtrim((string) ($meta['path'] ?? ''), '/\\');

            if ($path === '' || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $modulesDir).'/')) {
                continue;
            }

            try {
                $manifest = ModuleManifest::fromDirectory($path);
            } catch (Throwable) {
                continue;
            }

            $dirs[] = $manifest?->dirName ?? basename($path);
        }

        return array_values(array_unique($dirs));
    }
}
