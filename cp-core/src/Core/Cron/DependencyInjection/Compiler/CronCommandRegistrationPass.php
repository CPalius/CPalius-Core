<?php

declare(strict_types=1);

namespace App\Core\Cron\DependencyInjection\Compiler;

use App\Core\Cron\CronCommandWhitelist;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Compile-time fill of CronCommandWhitelist: scan Core/Command + module Command dirs for #[AsCommand].
 * Only "cp:" names are collected; vendor/Doctrine commands never enter the list.
 */
final class CronCommandRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';
    private const ALLOWED_PREFIX = 'cp:';

    /**
     * Hidden from the DB cron form: internal bridge for known virtual job names only.
     */
    private const EXCLUDED_COMMAND_NAMES = ['cp:cron:run-virtual'];

    public const CONTAINER_PARAMETER = 'cpalius.cron_allowed_commands';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<string> $collected */
        $collected = [];

        $coreCommandDir = $projectDir.'/cp-core/src/Core/Command';
        foreach ($this->scanDirectory($coreCommandDir, 'App\\Core\\Command\\', $container) as $name) {
            $collected[] = $name;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleCommandDir = rtrim((string) $bundleMeta['path'], '/').'/Command';
            $moduleCommandNamespace = $bundleMeta['namespace'].'\\Command\\';

            try {
                foreach ($this->scanDirectory($moduleCommandDir, $moduleCommandNamespace, $container) as $name) {
                    $collected[] = $name;
                }
            } catch (Throwable) {
                // Module isolation: a scan error drops only that module's commands.
            }
        }

        sort($collected);
        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(CronCommandWhitelist::class)) {
            $container->getDefinition(CronCommandWhitelist::class)
                ->setArgument('$allowedCommandNames', $collected);
        }
    }

    /**
     * @return list<string>
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

                $commandAttributes = $reflection->getAttributes(AsCommand::class);
                if ($commandAttributes === []) {
                    continue;
                }

                /** @var AsCommand $asCommand */
                $asCommand = $commandAttributes[0]->newInstance();
                $commandName = $asCommand->name;

                if ($commandName !== null
                    && str_starts_with($commandName, self::ALLOWED_PREFIX)
                    && !in_array($commandName, self::EXCLUDED_COMMAND_NAMES, true)
                ) {
                    $collected[] = $commandName;
                }
            } catch (Throwable) {
                // Skip one file on reflection failure; do not abort the whole scan.
                continue;
            }
        }

        return $collected;
    }
}
