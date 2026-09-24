<?php

declare(strict_types=1);

namespace App\Core\Asset;

/**
 * Copies the Tailwind output that ships with a release into the directory
 * the asset compiler reads.
 *
 * tailwind:build writes cp-core/var/tailwind/*.built.css. That tree is never
 * part of an update: var/ holds the site's cache and is left untouched. The
 * release therefore carries the same bytes under cp-core/assets/tailwind/.
 * Without this copy, asset-map:compile deletes the manifest and then dies
 * looking for a file the package was not allowed to install.
 */
final class TailwindBuildSeeder
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function seed(): bool
    {
        $sourceDir = $this->projectDir.'/cp-core/assets/tailwind';
        $targetDir = $this->projectDir.'/cp-core/var/tailwind';
        $sources = is_dir($sourceDir) ? (glob($sourceDir.'/*.built.css') ?: []) : [];

        if ($sources === []) {
            return is_file($targetDir.'/app.built.css');
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return false;
        }

        foreach ($sources as $source) {
            $dest = $targetDir.'/'.basename($source);
            if (is_file($dest) && filesize($dest) === filesize($source)) {
                continue;
            }
            if (!@copy($source, $dest)) {
                return false;
            }
        }

        return is_file($targetDir.'/app.built.css');
    }
}
