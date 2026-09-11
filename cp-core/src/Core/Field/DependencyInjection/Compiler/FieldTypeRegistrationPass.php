<?php

declare(strict_types=1);

namespace App\Core\Field\DependencyInjection\Compiler;

use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldTypeInterface;
use App\Core\Field\FieldTypeRegistry;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects #[CpFieldType] classes (core + modules) into FieldTypeRegistry.
 * A module scan error drops only that module's types — the core set is safe.
 * Duplicate ids keep the first registration (core wins over modules).
 */
final class FieldTypeRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FieldTypeRegistry::class)) {
            return;
        }

        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var array<string, string> $serviceByType type id => service id */
        $serviceByType = [];

        foreach ($this->scan($projectDir.'/cp-core/src', 'App\\', $container) as $id => $className) {
            $serviceByType[$id] ??= $className;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $meta) {
            $bundleClass = $meta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            try {
                foreach ($this->scan(rtrim((string) $meta['path'], '/'), $meta['namespace'].'\\', $container) as $id => $className) {
                    $serviceByType[$id] ??= $className;
                }
            } catch (\Throwable) {
                // Module isolation: a broken module drops only its own field types.
            }
        }

        $locator = [];
        foreach ($serviceByType as $className) {
            $locator[$className] = new Reference($className);
        }

        $definition = $container->getDefinition(FieldTypeRegistry::class);
        $definition->setArgument('$locator', ServiceLocatorTagPass::register($container, $locator));
        $definition->setArgument('$serviceByType', $serviceByType);
    }

    /**
     * @return array<string, string> type id => class name
     */
    private function scan(string $dir, string $namespacePrefix, ContainerBuilder $container): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($dir, '/\.php$/'));

        $found = [];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $relative = ltrim(substr((string) $file->getPathname(), \strlen($dir)), '/\\');
            $className = $namespacePrefix.str_replace(['/', '\\'], '\\', substr($relative, 0, -4));

            try {
                if (!class_exists($className)) {
                    continue;
                }
                $reflection = new \ReflectionClass($className);
                if ($reflection->isAbstract() || $reflection->getAttributes(CpFieldType::class) === []) {
                    continue;
                }
                if (!$reflection->implementsInterface(FieldTypeInterface::class) || !$container->has($className)) {
                    continue;
                }

                $id = $className::id();
                if (\is_string($id) && $id !== '') {
                    $found[$id] = $className;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $found;
    }
}
