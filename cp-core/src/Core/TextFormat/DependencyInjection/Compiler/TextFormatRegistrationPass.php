<?php

declare(strict_types=1);

namespace App\Core\TextFormat\DependencyInjection\Compiler;

use App\Core\TextFormat\TextFormatRegistry;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Yaml\Yaml;

/**
 * Compile-time fill of TextFormatRegistry: core text_formats.yaml, then
 * per-module Resources/config/text_formats.yaml. A broken module file drops
 * only that module's formats — mirrors ViewModeRegistrationPass.
 */
final class TextFormatRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TextFormatRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(TextFormatRegistry::class);

        $coreFile = $container->getParameter('kernel.project_dir').'/cp-core/config/text_formats.yaml';
        $this->registerFromFile($definition, $coreFile, $container);

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleFile = rtrim((string) $bundleMeta['path'], '/').'/Resources/config/text_formats.yaml';

            try {
                $this->registerFromFile($definition, $moduleFile, $container);
            } catch (\Throwable) {
                // Module isolation: a broken text_formats.yaml drops only that module's formats.
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
        $formats = $data['text_formats'] ?? null;
        if (!\is_array($formats)) {
            return;
        }

        foreach ($formats as $id => $spec) {
            if (!\is_string($id) || preg_match(TextFormatRegistry::ID_PATTERN, $id) !== 1 || !\is_array($spec)) {
                continue;
            }

            $definition->addMethodCall('register', [$id, $spec]);
        }
    }
}
