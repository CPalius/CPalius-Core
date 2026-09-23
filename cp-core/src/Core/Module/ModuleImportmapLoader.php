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
                    // asset-map:compile --env=prod dies if an importmap path is
                    // listed but the file is absent (or the mapper path was not
                    // registered). A disabled/partially-copied module must not
                    // take the whole rebuild down.
                    $absolute = $projectDir.'/'.ltrim($entry['path'], './');
                    if (!is_file($absolute)) {
                        continue;
                    }
                }

                $core[$name] = $entry;
            }
        }

        return $core;
    }

    /**
     * A module asset path the asset mapper can actually resolve.
     *
     * Always the full project-relative path. The obvious shortcut — strip
     * "Resources/assets/" and rely on the bare filename already being a
     * logical path — looks right and is not. AssetMapper resolves a path
     * beginning with "./" against the project root, so "./media-picker.js"
     * matches nothing there; it then registers a second asset under the
     * literal logical path "./media-picker.js", and every URL built from it
     * comes out as "/assets/./media-picker-HASH.js". Browsers normalise the
     * "/./" away before sending the request, the server receives a path
     * matching no logical path, and the module script 404s.
     *
     * The full path resolves through getAssetFromSourcePath() to the clean
     * logical path the asset map already holds, so the URL is
     * "/assets/media-picker-HASH.js" and the bare specifier resolves.
     */
    private static function resolvePath(string $dirName, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, './');

        if (str_starts_with($path, 'cp-content/modules/')) {
            return './'.$path;
        }

        return './cp-content/modules/'.$dirName.'/'.$path;
    }
}
