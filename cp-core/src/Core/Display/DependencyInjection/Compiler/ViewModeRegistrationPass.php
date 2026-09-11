<?php

declare(strict_types=1);

namespace App\Core\Display\DependencyInjection\Compiler;

use App\Core\Display\ViewModeRegistry;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Yaml\Yaml;

/**
 * Compile-time fill of ViewModeRegistry: core view_modes.yaml, then per-module
 * Resources/config/view_modes.yaml. A broken module file drops only that
 * module's view modes — mirrors CapabilityRegistrationPass exactly.
 */
final class ViewModeRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ViewModeRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(ViewModeRegistry::class);

        $coreFile = $container->getParameter('kernel.project_dir').'/cp-core/config/view_modes.yaml';
        $this->registerFromFile($definition, $coreFile, $container);

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleFile = rtrim((string) $bundleMeta['path'], '/').'/Resources/config/view_modes.yaml';

            try {
                $this->registerFromFile($definition, $moduleFile, $container);
            } catch (\Throwable) {
                // Module isolation: a broken view_modes.yaml drops only that module's modes.
            }
        }
    }

    private function registerFromFile(Definition $definition, string $file, ContainerBuilder $container): void
    {
        if (!is_file($file)) {
            return;
        }

        $container->addResource(new FileResource($file));

        $data = Yaml::parseFile($file);
        $modes = $data['view_modes'] ?? null;
        if (!\is_array($modes)) {
            return;
        }

        foreach ($modes as $id => $row) {
            if (!\is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id) !== 1 || !\is_array($row)) {
                continue;
            }

            $label = $row['label'] ?? $id;
            if (\is_string($label) && $label !== '') {
                $definition->addMethodCall('register', [$id, $label]);
            }
        }
    }
}
