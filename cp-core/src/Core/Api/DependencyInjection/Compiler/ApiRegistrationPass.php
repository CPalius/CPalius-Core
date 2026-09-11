<?php

declare(strict_types=1);

namespace App\Core\Api\DependencyInjection\Compiler;

use App\Core\Api\Attribute\CpApi;
use App\Core\Api\Controller\ApiGatewayController;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * Compile-time collector for #[CpApi] methods; binds them to the gateway's lazy locator.
 * Duplicate path+method pairs keep the first definition; later ones are skipped (never abort compile).
 */
final class ApiRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public const CONTAINER_PARAMETER = 'cpalius.api_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{path: string, methods: list<string>, public: bool, capability: ?string, serviceId: string, method: string}> $collected */
        $collected = [];
        $seen = [];
        $serviceIds = [];

        $coreServiceDir = $projectDir.'/cp-core/src';
        foreach ($this->scanDirectory($coreServiceDir, 'App\\', $container) as $item) {
            $signature = $item['path'].'|'.implode(',', $item['methods']);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $collected[] = $item;
            $serviceIds[$item['serviceId']] = true;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleDir = rtrim((string) $bundleMeta['path'], '/');
            $moduleNamespace = $bundleMeta['namespace'].'\\';

            try {
                foreach ($this->scanDirectory($moduleDir, $moduleNamespace, $container) as $item) {
                    $signature = $item['path'].'|'.implode(',', $item['methods']);
                    if (isset($seen[$signature])) {
                        continue;
                    }
                    $seen[$signature] = true;
                    $collected[] = $item;
                    $serviceIds[$item['serviceId']] = true;
                }
            } catch (Throwable) {
                // Module isolation: a scan error drops only that module's API endpoints.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(ApiGatewayController::class)) {
            $locatorReferences = [];
            foreach (array_keys($serviceIds) as $serviceId) {
                if ($container->has($serviceId)) {
                    $locatorReferences[$serviceId] = new Reference($serviceId);
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $locatorReferences);

            $definition = $container->getDefinition(ApiGatewayController::class);
            $definition->setArgument('$serviceLocator', $locatorReference);
        }
    }

    /**
     * @return list<array{path: string, methods: list<string>, public: bool, capability: ?string, serviceId: string, method: string}>
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
                if (!class_exists($className) && !interface_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }

                    $apiAttributes = $method->getAttributes(CpApi::class);
                    if ($apiAttributes === []) {
                        continue;
                    }

                    if (!$container->has($className)) {
                        continue;
                    }

                    /** @var CpApi $api */
                    $api = $apiAttributes[0]->newInstance();

                    $normalizedPath = '/'.ltrim($api->path, '/');
                    $normalizedMethods = array_values(array_unique(array_map(
                        static fn (string $m): string => strtoupper($m),
                        $api->methods,
                    )));

                    $collected[] = [
                        'path' => $normalizedPath,
                        'methods' => $normalizedMethods,
                        'public' => $api->public,
                        'capability' => $api->capability,
                        'serviceId' => $className,
                        'method' => $method->getName(),
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $collected;
    }
}
