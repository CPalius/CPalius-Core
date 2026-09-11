<?php

declare(strict_types=1);

namespace App\Core\Resource\DependencyInjection\Compiler;

use App\Core\Annotation\Auditable;
use App\Core\Annotation\CpResource;
use App\Core\Annotation\Publishable;
use App\Core\Annotation\SoftDeletable;
use App\Core\Resource\ResourceDefinition;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Populates ResourceRegistry at compile time via filesystem scan + reflection (not Doctrine metadata).
 * Scans cp-core/src/Entity and each module src/Entity; module isolation skips broken modules without aborting build.
 */
final class ResourceRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    /** Container parameter read by CapabilityRegistrationPass for compile-time #[CpResource] definitions. */
    public const CONTAINER_PARAMETER = 'cpalius.resource_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{class: string, name: string, module: string, capabilities: list<string>, auditable: bool, multiTenant: bool, workflow: ?string, publishable: bool, softDeletable: bool}> $collected */
        $collected = [];

        $coreEntityDir = $projectDir.'/cp-core/src/Entity';
        foreach ($this->scanDirectory($coreEntityDir, 'App\\Entity\\', $container) as $resource) {
            $collected[] = $resource;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleEntityDir = rtrim((string) $bundleMeta['path'], '/').'/src/Entity';
            $moduleEntityNamespace = $bundleMeta['namespace'].'\\Entity\\';

            try {
                foreach ($this->scanDirectory($moduleEntityDir, $moduleEntityNamespace, $container) as $resource) {
                    $collected[] = $resource;
                }
            } catch (Throwable) {
                // Module isolation: scan failure skips that module's resources only.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition('App\Core\Resource\ResourceRegistry')) {
            $definition = $container->getDefinition('App\Core\Resource\ResourceRegistry');

            foreach ($collected as $resource) {
                $definition->addMethodCall('add', [new \Symfony\Component\DependencyInjection\Definition(ResourceDefinition::class, [
                    $resource['class'],
                    $resource['name'],
                    $resource['module'],
                    $resource['capabilities'],
                    $resource['auditable'],
                    $resource['multiTenant'],
                    $resource['workflow'],
                    $resource['publishable'],
                    $resource['softDeletable'],
                ])]);
            }
        }
    }

    /**
     * @return list<array{class: string, name: string, module: string, capabilities: list<string>, auditable: bool, multiTenant: bool, workflow: ?string, publishable: bool, softDeletable: bool}>
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

                $cpResourceAttributes = $reflection->getAttributes(CpResource::class);
                [$publishable, $softDeletable, $auditableBehavior] = $this->detectBehaviors($reflection);

                // Behavior-only entities without #[CpResource] still register with empty name/capabilities.
                if ($cpResourceAttributes === [] && !$publishable && !$softDeletable && !$auditableBehavior) {
                    continue;
                }

                if ($cpResourceAttributes !== []) {
                    /** @var CpResource $resource */
                    $resource = $cpResourceAttributes[0]->newInstance();

                    $collected[] = [
                        'class' => $className,
                        'name' => $resource->name,
                        'module' => $resource->module,
                        'capabilities' => $resource->capabilities,
                        'auditable' => $resource->auditable || $auditableBehavior,
                        'multiTenant' => $resource->multiTenant,
                        'workflow' => $resource->workflow,
                        'publishable' => $publishable,
                        'softDeletable' => $softDeletable,
                    ];
                } else {
                    $collected[] = [
                        'class' => $className,
                        'name' => '',
                        'module' => 'core',
                        'capabilities' => [],
                        'auditable' => $auditableBehavior,
                        'multiTenant' => false,
                        'workflow' => null,
                        'publishable' => $publishable,
                        'softDeletable' => $softDeletable,
                    ];
                }
            } catch (Throwable) {
                // Reflection failure on one file skips that file; scan continues.
                continue;
            }
        }

        return $collected;
    }

    /**
     * Detects #[Publishable], #[SoftDeletable], #[Auditable] on an entity (independent of #[CpResource]).
     *
     * @return array{0: bool, 1: bool, 2: bool} [publishable, softDeletable, auditable]
     */
    private function detectBehaviors(\ReflectionClass $reflection): array
    {
        return [
            $reflection->getAttributes(Publishable::class) !== [],
            $reflection->getAttributes(SoftDeletable::class) !== [],
            $reflection->getAttributes(Auditable::class) !== [],
        ];
    }
}
