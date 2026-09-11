<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Merges active-module importmaps into the root map at read time (no core file edits).
 * Runs before the container exists, so this class is autoload-only.
 */
final class ModuleImportmapLoader
{
    /**
     * @param array<string, array<string, mixed>> $core
     *
     * @return array<string, array<string, mixed>>
     */
    public static function merge(array $core, string $projectDir): array
    {
        $activeFile = $projectDir.'/cp-core/config/active_modules.php';
        $modulesDir = $projectDir.'/cp-content/modules';

        if (!is_file($activeFile) || !is_dir($modulesDir)) {
            return $core;
        }

        try {
            $active = require $activeFile;
        } catch (\Throwable) {
            return $core;
        }

        if (!\is_array($active)) {
            return $core;
        }

        foreach ($active as $class) {
            if (!\is_string($class) || !str_starts_with($class, 'Modules\\')) {
                continue;
            }

            $parts = explode('\\', $class);
            $dirName = $parts[1] ?? '';
            if ($dirName === '' || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $dirName)) {
                continue;
            }

            $file = $modulesDir.'/'.$dirName.'/Resources/config/importmap.php';
            if (!is_file($file)) {
                continue;
            }

            try {
                $extra = require $file;
            } catch (\Throwable) {
                continue;
            }

            if (!\is_array($extra)) {
                continue;
            }

            foreach ($extra as $name => $entry) {
                if (!\is_string($name) || $name === '' || !\is_array($entry)) {
                    continue;
                }

                if (isset($entry['path']) && \is_string($entry['path'])) {
                    $entry['path'] = self::resolvePath($dirName, $entry['path']);
                }

                $core[$name] = $entry;
            }
        }

        return $core;
    }

    private static function resolvePath(string $dirName, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, './');

        if (str_starts_with($path, 'Resources/assets/')) {
            return './'.substr($path, \strlen('Resources/assets/'));
        }

        if (str_starts_with($path, 'cp-content/modules/')) {
            return './'.$path;
        }

        return './cp-content/modules/'.$dirName.'/'.$path;
    }
}
