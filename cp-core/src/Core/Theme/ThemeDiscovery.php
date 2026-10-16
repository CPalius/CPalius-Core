<?php

declare(strict_types=1);

namespace App\Core\Theme;

/**
 * Single theme directory scan, usable at runtime (ThemeRegistry) and at container compile time.
 */
final class ThemeDiscovery
{
    /**
     * @return array<string, ThemeDefinition> keyed and sorted by directory name; broken themes are skipped
     */
    public static function scan(string $themesDir): array
    {
        $themes = [];

        foreach (is_dir($themesDir) ? (scandir($themesDir) ?: []) : [] as $dirName) {
            if ($dirName === '.' || $dirName === '..' || !is_dir($themesDir.'/'.$dirName)) {
                continue;
            }

            $definition = ThemeDefinition::fromDirectory($themesDir.'/'.$dirName);

            if ($definition !== null) {
                $themes[$dirName] = $definition;
            }
        }

        ksort($themes);

        return $themes;
    }
}
