<?php

declare(strict_types=1);

namespace App\Core\Entity\DependencyInjection\Compiler;

use App\Core\Entity\Attribute\CpEntityType;
use App\Core\Entity\EntityTypeRegistry;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects #[CpEntityType] classes (core + modules) into EntityTypeRegistry.
 * Entities are not services, so this scans source files and reflects — the same
 * approach as FieldTypeRegistrationPass. A module scan error drops only that
 * module's entity types; the core set stays intact. Duplicate ids: first wins
 * (core over modules).
 */
final class EntityTypeRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EntityTypeRegistry::class)) {
            return;
        }

        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var array<string, array<string, mixed>> $found id => payload */
        $found = [];

        foreach ($this->scan($projectDir.'/cp-core/src', 'App\\', $container) as $id => $payload) {
            $found[$id] ??= $payload;
        }

        /** @var array<string, array{namespace: string, path: string}> $bundlesMetadata */
        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        foreach ($bundlesMetadata as $bundleName => $meta) {
            $bundleClass = $meta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            try {
                foreach ($this->scan(rtrim((string) $meta['path'], '/'), $meta['namespace'].'\\', $container) as $id => $payload) {
                    $found[$id] ??= $payload;
                }
            } catch (\Throwable) {
                // Module isolation: a broken module drops only its own entity types.
            }
        }

        $container->getDefinition(EntityTypeRegistry::class)->setArgument('$rawDefinitions', $found);
    }

    /**
     * @return array<string, array<string, mixed>> id => payload
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
                if ($reflection->isAbstract()) {
                    continue;
                }

                $attributes = $reflection->getAttributes(CpEntityType::class);
                if ($attributes === []) {
                    continue;
                }

                /** @var CpEntityType $attribute */
                $attribute = $attributes[0]->newInstance();
                if ($attribute->id === '') {
                    continue;
                }

                $found[$attribute->id] = [
                    'id' => $attribute->id,
                    'className' => $className,
                    'label' => $attribute->label,
                    'fieldable' => $attribute->fieldable,
                    'bundleable' => $attribute->bundleable,
                    'revisionable' => $attribute->revisionable,
                    'translatable' => $attribute->translatable,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $found;
    }
}
