<?php

declare(strict_types=1);

namespace App\Core\Token\DependencyInjection\Compiler;

use App\Core\Token\TokenTypeRegistry;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Yaml\Yaml;

/**
 * Compile-time fill of TokenTypeRegistry: core tokens.yaml, then per-module
 * Resources/config/tokens.yaml. A broken module file drops only that module's
 * tokens — mirrors ViewModeRegistrationPass exactly.
 */
final class TokenTypeRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TokenTypeRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(TokenTypeRegistry::class);

        $coreFile = $container->getParameter('kernel.project_dir').'/cp-core/config/tokens.yaml';
        $this->registerFromFile($definition, $coreFile, $container);

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleFile = rtrim((string) $bundleMeta['path'], '/').'/Resources/config/tokens.yaml';

            try {
                $this->registerFromFile($definition, $moduleFile, $container);
            } catch (\Throwable) {
                // Module isolation: a broken tokens.yaml drops only that module's tokens.
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
        $types = $data['tokens'] ?? null;
        if (!\is_array($types)) {
            return;
        }

        foreach ($types as $type => $properties) {
            if (!\is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $type) !== 1 || !\is_array($properties)) {
                continue;
            }

            foreach ($properties as $property => $label) {
                if (!\is_string($property) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $property) !== 1) {
                    continue;
                }

                $label = \is_string($label) && $label !== '' ? $label : $property;
                $definition->addMethodCall('register', [$type, $property, $label]);
            }
        }
    }
}
