<?php

declare(strict_types=1);

namespace App\Core\Theme;

/**
 * Hard package rules for every theme under cp-content/themes.
 * ZIP install and activate refuse the package when any problem is returned.
 */
final class ThemePackageContract
{
    public const DIR_NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @return list<string>
     */
    public static function problems(string $themeDir): array
    {
        $themeDir = rtrim($themeDir, '/\\');
        $dirName = basename($themeDir);
        $problems = [];

        if (preg_match(self::DIR_NAME_PATTERN, $dirName) !== 1) {
            $problems[] = 'Theme directory name must be kebab-case (e.g. cpalius-website).';
        }

        $definition = ThemeDefinition::fromDirectory($themeDir);
        if ($definition === null) {
            $problems[] = 'theme.json is missing or invalid.';

            return $problems;
        }

        if ($definition->name === '') {
            $problems[] = 'theme.json is missing a "name" field.';
        }

        if ($definition->version === '' || $definition->version === '0.0.0') {
            // 0.0.0 is the parser fallback when version is omitted.
            $raw = json_decode((string) file_get_contents($themeDir.'/theme.json'), true);
            if (!\is_array($raw) || !\is_string($raw['version'] ?? null) || $raw['version'] === '') {
                $problems[] = 'theme.json is missing a "version" field.';
            }
        }

        $layout = $themeDir.'/Resources/views/layout.html.twig';
        if (!is_file($layout)) {
            $problems[] = 'Missing Resources/views/layout.html.twig (front-end layout).';
        }

        return array_merge($problems, self::isolationProblems($themeDir));
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

    /**
     * @return list<string>
     */
    private static function isolationProblems(string $themeDir): array
    {
        $problems = [];
        if (!is_dir($themeDir)) {
            return $problems;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($themeDir) + 1));
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+App(?:\\\\|;)/m', $contents) === 1) {
                $problems[] = sprintf('%s must not declare namespace App\\ (core is untouchable).', $relative);
            }
        }

        return $problems;
    }
}
