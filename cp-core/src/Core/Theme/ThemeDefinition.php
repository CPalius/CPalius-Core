<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Module\ModuleManifest;

/**
 * Parsed, immutable theme.json contract.
 * Every field except the directory name is optional so a minimal theme still loads.
 */
final class ThemeDefinition
{
    /**
     * @param list<string> $css      theme-relative stylesheet paths
     * @param list<string> $js       theme-relative script paths
     * @param list<string> $supports module ids the theme ships templates for
     */
    public function __construct(
        public readonly string $dirName,
        public readonly string $name,
        public readonly string $version = '0.0.0',
        public readonly string $description = '',
        public readonly string $author = '',
        public readonly ?string $screenshot = null,
        public readonly array $css = [],
        public readonly array $js = [],
        public readonly array $supports = [],
        public readonly array $requiresModules = [],
    ) {
    }

    /**
     * Required modules (theme.json "requires_modules", directory names) that are not booted.
     * A theme that calls a module's Twig function would otherwise take the whole site down
     * the moment that module is switched off.
     *
     * @param array<string, string> $bundles kernel.bundles (name => class)
     *
     * @return list<string>
     */
    public function unmetModules(array $bundles, string $modulesDir): array
    {
        $booted = array_values($bundles);

        return array_values(array_filter(
            $this->requiresModules,
            static fn (string $dir): bool => !\in_array(ModuleManifest::fromDirectory(rtrim($modulesDir, '/\\').'/'.$dir)?->bundle, $booted, true),
        ));
    }

    /**
     * Reads <themeDir>/theme.json. Returns null when missing or invalid, so one broken
     * theme never breaks the theme list.
     */
    public static function fromDirectory(string $themeDir): ?self
    {
        $manifestFile = rtrim($themeDir, '/\\').'/theme.json';

        if (!is_file($manifestFile)) {
            return null;
        }

        try {
            $contents = file_get_contents($manifestFile);
            $data = $contents === false ? null : json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        $dirName = basename(rtrim($themeDir, '/\\'));
        $entry = \is_array($data['entry'] ?? null) ? $data['entry'] : [];

        return new self(
            dirName: $dirName,
            name: self::stringOr($data['name'] ?? null, $dirName),
            version: self::stringOr($data['version'] ?? null, '0.0.0'),
            description: self::stringOr($data['description'] ?? null, ''),
            author: self::stringOr($data['author'] ?? null, ''),
            screenshot: \is_string($data['screenshot'] ?? null) && $data['screenshot'] !== '' ? $data['screenshot'] : null,
            css: self::stringList($entry['css'] ?? null),
            js: self::stringList($entry['js'] ?? null),
            supports: self::stringList($data['supports'] ?? null),
            requiresModules: self::stringList($data['requires_modules'] ?? null),
        );
    }

    /** Twig namespace this theme's views are registered under, e.g. "@CpaliusWebsiteTheme". */
    public function twigNamespace(): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $this->dirName)));

        return $studly.'Theme';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $raw),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private static function stringOr(mixed $raw, string $fallback): string
    {
        return \is_string($raw) && $raw !== '' ? $raw : $fallback;
    }
}
