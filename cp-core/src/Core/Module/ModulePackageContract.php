<?php

declare(strict_types=1);

namespace App\Core\Module;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Hard package rules for every module under cp-content/modules.
 * ZIP install and activate refuse the package when any problem is returned.
 *
 * Modules may use App\Core contracts. They must never:
 * - live in or write into cp-core/
 * - declare namespace App\
 * - omit the English catalogue, installer, or module.json bundle
 */
final class ModulePackageContract
{
    public const DIR_NAME_PATTERN = '/^[A-Z][A-Za-z0-9]+$/';

    /**
     * @return list<string>
     */
    public static function problems(string $moduleDir): array
    {
        $moduleDir = rtrim($moduleDir, '/\\');
        $dirName = basename($moduleDir);
        $problems = [];

        if (preg_match(self::DIR_NAME_PATTERN, $dirName) !== 1) {
            $problems[] = 'Module directory name must be PascalCase alphanumeric (e.g. Pages).';
        }

        $problems = array_merge($problems, ModuleTranslationContract::problems($moduleDir));

        if (!is_file(ModuleTranslationContract::installerPath($moduleDir))) {
            $problems[] = 'Missing Install/ModuleInstaller.php (install / uninstall / upgrade hooks).';
        }

        $manifest = ModuleManifest::fromDirectory($moduleDir);
        if ($manifest === null) {
            $problems[] = 'module.json is missing or invalid.';

            return $problems;
        }

        if ($manifest->bundle === null) {
            $problems[] = 'module.json is missing a valid "bundle" field.';
        } else {
            $expectedBundle = 'Modules\\'.$dirName.'\\'.$dirName.'Module';
            if ($manifest->bundle !== $expectedBundle) {
                $problems[] = sprintf('module.json "bundle" must be %s.', $expectedBundle);
            }

            $bundleFile = $moduleDir.'/'.$dirName.'Module.php';
            if (!is_file($bundleFile)) {
                $problems[] = sprintf('Missing bundle class file %sModule.php.', $dirName);
            } elseif (!self::fileLooksLikeBundle($bundleFile, $dirName)) {
                $problems[] = sprintf('%sModule.php must declare class %sModule extending Symfony Bundle.', $dirName, $dirName);
            }
        }

        $contributions = $moduleDir.'/Resources/config/contributions.yaml';
        if (is_file($contributions)) {
            try {
                Yaml::parseFile($contributions);
            } catch (ParseException $e) {
                $problems[] = 'Resources/config/contributions.yaml is not valid YAML: '.$e->getMessage();
            }
        }

        return array_merge($problems, self::isolationProblems($moduleDir));
    }

    /**
     * @return list<string>
     */
    public static function zipEntryProblems(string $entryName): array
    {
        $normalized = str_replace('\\', '/', $entryName);
        $lower = strtolower($normalized);

        if (str_contains($normalized, '..') || str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            return [$entryName];
        }

        if (str_contains($lower, 'cp-core/') || str_contains($lower, 'cp-includes/')) {
            return [sprintf('Package must not include core paths: %s', $entryName)];
        }

        return [];
    }

    private static function fileLooksLikeBundle(string $bundleFile, string $dirName): bool
    {
        $contents = (string) file_get_contents($bundleFile);
        $class = $dirName.'Module';

        return str_contains($contents, 'namespace Modules\\'.$dirName)
            && (str_contains($contents, 'class '.$class) || str_contains($contents, 'class '.$class.' '))
            && (str_contains($contents, 'extends Bundle') || str_contains($contents, '\\'.Bundle::class));
    }

    /**
     * @return list<string>
     */
    private static function isolationProblems(string $moduleDir): array
    {
        $problems = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleDir) + 1));
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+App(?:\\\\|;)/m', $contents) === 1) {
                $problems[] = sprintf('%s must not declare namespace App\\ (core is untouchable).', $relative);
            }

            if (str_starts_with($relative, 'Plugin/') && str_ends_with($relative, 'Plugin.php')
                && !str_contains($contents, 'implements PluginInterface')
            ) {
                $problems[] = sprintf('%s must implement App\\Core\\Plugin\\PluginInterface.', $relative);
            }
        }

        return $problems;
    }
}
