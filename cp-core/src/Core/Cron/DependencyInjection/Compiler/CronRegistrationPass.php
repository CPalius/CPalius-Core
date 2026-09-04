<?php

declare(strict_types=1);

namespace App\Core\Cron\DependencyInjection\Compiler;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Cron\CronManager;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * Compile-time collector for #[CpCronJob] and Hooks/cron.{job_name}.php (isolated per module).
 * The "cron." prefix avoids colliding with hook-point files in the same Hooks/ directory.
 */
final class CronRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';
    private const FLAT_FILE_PREFIX = 'cron.';

    public const CONTAINER_PARAMETER = 'cpalius.cron_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute'|'flat-file', serviceId: ?string, method: ?string, file: ?string}> $collected */
        $collected = [];
        $serviceIds = [];

        $coreServiceDir = $projectDir.'/cp-core/src';
        foreach ($this->scanAttributeDirectory($coreServiceDir, 'App\\', $container) as $item) {
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
                foreach ($this->scanAttributeDirectory($moduleDir, $moduleNamespace, $container) as $item) {
                    $collected[] = $item;
                    $serviceIds[$item['serviceId']] = true;
                }
            } catch (Throwable) {
                // Module isolation: a scan error drops only that module's attribute cron jobs.
            }

            try {
                foreach ($this->scanFlatFileDirectory($moduleDir.'/Hooks', $container) as $item) {
                    $collected[] = $item;
                }
            } catch (Throwable) {
                // Module isolation: same as the attribute-scan catch above.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(CronManager::class)) {
            $locatorReferences = [];
            foreach (array_keys($serviceIds) as $serviceId) {
                if ($container->has($serviceId)) {
                    $locatorReferences[$serviceId] = new Reference($serviceId);
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $locatorReferences);

            $definition = $container->getDefinition(CronManager::class);
            $definition->setArgument('$serviceLocator', $locatorReference);
        }
    }

    /**
     * @return list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute', serviceId: string, method: string, file: null}>
     */
    private function scanAttributeDirectory(string $dir, string $namespacePrefix, ContainerBuilder $container): array
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

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }

                    $cronAttributes = $method->getAttributes(CpCronJob::class);
                    if ($cronAttributes === []) {
                        continue;
                    }

                    if (!$container->has($className)) {
                        continue;
                    }

                    /** @var CpCronJob $cronJob */
                    $cronJob = $cronAttributes[0]->newInstance();

                    $collected[] = [
                        'jobName' => $cronJob->name,
                        'schedule' => $cronJob->schedule,
                        'description' => $cronJob->description,
                        'sourceType' => 'attribute',
                        'serviceId' => $className,
                        'method' => $method->getName(),
                        'file' => null,
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $collected;
    }

    /**
     * Filename is the contract (cron.{job_name}.php); schedule comes from the returned array, not a header comment.
     *
     * @return list<array{jobName: string, schedule: string, description: string, sourceType: 'flat-file', serviceId: null, method: null, file: string}>
     */
    private function scanFlatFileDirectory(string $hooksDir, ContainerBuilder $container): array
    {
        if (!is_dir($hooksDir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($hooksDir, '/\.php$/'));

        $collected = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hooksDir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $filename = (string) $file->getFilename();

            if (!str_starts_with($filename, self::FLAT_FILE_PREFIX)) {
                continue;
            }

            $jobName = substr($filename, strlen(self::FLAT_FILE_PREFIX), -4);
            if ($jobName === '') {
                continue;
            }

            try {
                $definition = include $file->getPathname();
            } catch (Throwable) {
                continue;
            }

            if (!is_array($definition) || !isset($definition['schedule']) || !is_string($definition['schedule'])) {
                // Skip files that do not return an array with a string 'schedule' key.
                continue;
            }

            $collected[] = [
                'jobName' => $jobName,
                'schedule' => $definition['schedule'],
                'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
                'sourceType' => 'flat-file',
                'serviceId' => null,
                'method' => null,
                'file' => $file->getPathname(),
            ];
        }

        return $collected;
    }
}
