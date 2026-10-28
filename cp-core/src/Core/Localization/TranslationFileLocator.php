<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Theme\ThemePackageContract;

/**
 * Resolves YAML translation file paths: core + each module Resources/translations (locales and domains are dynamic).
 * Needed so AACP can write the right file, not only look up keys. One bad module dir does not abort the scan.
 */
final class TranslationFileLocator
{
    public const DEFAULT_DOMAIN = 'messages+intl-icu';

    /**
     * YAML only. XLIFF/PHP are out of scope so the panel does not rewrite foreign formats.
     */
    private const EXTENSION = 'yaml';

    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly string $coreTranslationsDir,
        private readonly string $modulesDir,
        private readonly string $themesDir = '',
    ) {
    }

    /**
     * Active locales that become AACP table columns.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->localeProvider->getCodes();
    }

    /**
     * @return array<string, array<string, string>> group label ("core:<domain>" or "<Module>:<domain>") => [locale => path]
     */
    public function locateAll(): array
    {
        $groups = $this->locateDir('core', $this->coreTranslationsDir);

        foreach ($this->discoverModuleTranslationDirs() as $moduleName => $dir) {
            foreach ($this->locateDir($moduleName, $dir) as $group => $filesByLocale) {
                $groups[$group] = $filesByLocale;
            }
        }

        // Theme packages ship their own catalogues: scope "theme/<dir>".
        foreach ($this->themesDir !== '' ? (glob(rtrim($this->themesDir, '/\\').'/*/Resources/translations', GLOB_ONLYDIR) ?: []) : [] as $dir) {
            foreach ($this->locateDir('theme/'.basename(dirname($dir, 2)), $dir) as $group => $filesByLocale) {
                $groups[$group] = $filesByLocale;
            }
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Collect domains and per-locale files in one directory.
     *
     * @return array<string, array<string, string>>
     */
    private function locateDir(string $scope, string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $locales = $this->locales();
        $groups = [];

        foreach (glob(rtrim($dir, '/\\').'/*.'.self::EXTENSION) ?: [] as $path) {
            $basename = basename($path, '.'.self::EXTENSION);
            $separator = strrpos($basename, '.');

            if ($separator === false) {
                continue;
            }

            $domain = substr($basename, 0, $separator);
            $locale = substr($basename, $separator + 1);

            if ($domain === '' || !\in_array($locale, $locales, true)) {
                continue;
            }

            $groups[$scope.':'.$domain][$locale] = $path;
        }

        return $groups;
    }

    /**
     * @return array<string, string> ModuleName => absolute Resources/translations path
     */
    private function discoverModuleTranslationDirs(): array
    {
        if (!is_dir($this->modulesDir)) {
            return [];
        }

        $dirs = [];

        foreach (glob(rtrim($this->modulesDir, '/\\').'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $translationsDir = $moduleDir.'/Resources/translations';

            if (is_dir($translationsDir)) {
                $dirs[basename($moduleDir)] = $translationsDir;
            }
        }

        ksort($dirs);

        return $dirs;
    }

    /**
     * Path for a file that may not exist yet (first edit of a new locale). Unknown group → null, never a random write.
     */
    public function resolveFilePath(string $group, string $locale): ?string
    {
        $separator = strpos($group, ':');

        if ($separator === false) {
            return null;
        }

        $scope = substr($group, 0, $separator);
        $domain = substr($group, $separator + 1);

        if ($domain === '') {
            return null;
        }

        if (str_starts_with($scope, 'theme/')) {
            $themeName = substr($scope, 6);
            // Name pattern guards the write path; the theme must already exist.
            if ($this->themesDir === '' || preg_match(ThemePackageContract::DIR_NAME_PATTERN, $themeName) !== 1 || !is_dir(rtrim($this->themesDir, '/\\').'/'.$themeName)) {
                return null;
            }
            $dir = rtrim($this->themesDir, '/\\').'/'.$themeName.'/Resources/translations';
        } else {
            $dir = $scope === 'core'
                ? $this->coreTranslationsDir
                : rtrim($this->modulesDir, '/\\').'/'.$scope.'/Resources/translations';

            if ($scope !== 'core' && !is_dir(rtrim($this->modulesDir, '/\\').'/'.$scope)) {
                return null;
            }
        }

        return rtrim($dir, '/\\').'/'.$domain.'.'.$locale.'.'.self::EXTENSION;
    }

    /**
     * Default file group for a brand-new key: core messages+intl-icu.
     */
    public function defaultGroup(): string
    {
        return 'core:'.self::DEFAULT_DOMAIN;
    }
}
