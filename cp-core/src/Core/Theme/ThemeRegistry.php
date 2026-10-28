<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Discovers themes under cp-content/themes and tracks which one is active.
 * The active theme is stored in the core.active_theme setting.
 */
final class ThemeRegistry
{
    public const ACTIVE_THEME_KEY = 'core.active_theme';
    public const FALLBACK_THEME = 'cpalius-website';

    /** @var array<string, ThemeDefinition>|null */
    private ?array $themes = null;

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $themesDir,
        private readonly string $projectDir,
        /** @var array<string, string> kernel.bundles: the modules that are booted */
        private readonly array $bundles = [],
        private readonly string $modulesDir = '',
    ) {
    }

    /**
     * All installed themes, keyed and sorted by directory name.
     *
     * @return array<string, ThemeDefinition>
     */
    public function all(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }

        return $this->themes = ThemeDiscovery::scan($this->themesDir);
    }

    public function get(string $dirName): ?ThemeDefinition
    {
        return $this->all()[$dirName] ?? null;
    }

    public function has(string $dirName): bool
    {
        return $this->get($dirName) !== null;
    }

    /**
     * The active theme, falling back to the first installed one so the front end
     * never renders against a theme that was deleted from disk.
     */
    public function active(): ?ThemeDefinition
    {
        $themes = $this->all();

        if ($themes === []) {
            return null;
        }

        $configured = (string) ($this->settingsRegistry->get(self::ACTIVE_THEME_KEY) ?? '');

        // Same rule as ThemeTwigPathPass: a theme whose module is off is not served.
        if ($configured !== '' && isset($themes[$configured]) && $this->unmetModules($themes[$configured]) === []) {
            return $themes[$configured];
        }

        return $themes[self::FALLBACK_THEME] ?? reset($themes);
    }

    /**
     * @return list<string> required modules that are not active
     */
    public function unmetModules(ThemeDefinition $theme): array
    {
        return $this->modulesDir === '' ? [] : $theme->unmetModules($this->bundles, $this->modulesDir);
    }

    public function activeDirName(): string
    {
        return $this->active()?->dirName ?? self::FALLBACK_THEME;
    }

    /**
     * Persists the active theme. Returns an error message, or null on success.
     * Twig namespaces are compiled, so the caller must clear the cache afterwards.
     */
    public function activate(string $dirName): ?string
    {
        if (!$this->has($dirName)) {
            return sprintf('Theme "%s" is not installed.', $dirName);
        }

        $problems = ThemePackageContract::problems($this->themesDir.'/'.$dirName);
        if ($problems !== []) {
            return implode(' ', $problems);
        }

        // The fallback theme must stay selectable: it is where a theme with missing modules lands.
        $unmet = $dirName === self::FALLBACK_THEME ? [] : $this->unmetModules($this->all()[$dirName]);
        if ($unmet !== []) {
            return sprintf('Theme "%s" needs the module(s) %s to be active first.', $dirName, implode(', ', $unmet));
        }

        try {
            $setting = $this->settingRepository->findOneBy(['settingKey' => self::ACTIVE_THEME_KEY]);

            if (!$setting instanceof Setting) {
                $setting = new Setting(self::ACTIVE_THEME_KEY, 'core');
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue($dirName);
            $this->entityManager->flush();
            $this->settingsRegistry->clearCache();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Template areas (top-level folders and files under Resources/views, e.g. "forum", "blog")
     * that other installed themes ship but this theme does not fully ship: those pages are
     * rendered from the other theme's templates inside this theme's layout. Keyed by area,
     * value = number of missing templates. Empty when the theme is self-contained.
     *
     * @return array<string, int>
     */
    public function fallbackAreas(ThemeDefinition $theme): array
    {
        $own = $this->viewFiles($theme->dirName);
        $missing = [];
        foreach ($this->all() as $dirName => $other) {
            if ($dirName === $theme->dirName) {
                continue;
            }
            foreach (array_keys($this->viewFiles($dirName)) as $file) {
                if (!isset($own[$file])) {
                    $area = explode('/', $file)[0];
                    $missing[$area][$file] = true;
                }
            }
        }
        ksort($missing);

        return array_map('count', $missing);
    }

    /**
     * @return array<string, true> relative template paths under Resources/views
     */
    private function viewFiles(string $dirName): array
    {
        $root = $this->themesDir.'/'.$dirName.'/Resources/views';
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($root))), '/')] = true;
            }
        }

        return $files;
    }

    /**
     * Public URL of a theme asset (e.g. "dist/style.css"), versioned by file mtime.
     *
     * The active theme is asked first, then the other installed themes, so a theme that
     * ships only part of the assets (githeme) still resolves the rest from Default.
     * The file is copied to public/themes/<dir>/ on first use or when the source is
     * newer, so a freshly uploaded or git-deployed theme never serves 404 assets.
     * Returns null when no theme has the file.
     */
    public function assetUrl(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/');
        if (preg_match('#^(dist|assets)/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $relativePath) !== 1 || str_contains($relativePath, '..')) {
            return null;
        }

        $order = array_keys($this->all());
        try {
            $active = $this->activeDirName();
        } catch (\Throwable) {
            // Error pages render while the database may be down: use the fallback theme.
            $active = self::FALLBACK_THEME;
        }
        array_unshift($order, $active);

        foreach (array_unique($order) as $dirName) {
            $source = $this->themesDir.'/'.$dirName.'/Resources/'.$relativePath;
            if (!is_file($source)) {
                continue;
            }

            $public = $this->projectDir.'/public/themes/'.$dirName.'/'.$relativePath;
            $mtime = (int) filemtime($source);
            // Extension zips stamp every file with one fixed time, so a newer version has the SAME mtime: size must count too.
            if (!is_file($public) || filemtime($public) !== $mtime || filesize($public) !== filesize($source)) {
                $dir = \dirname($public);
                if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
                    @copy($source, $public);
                    @touch($public, $mtime);
                }
            }

            // Fonts and images a stylesheet pulls in with url() never pass through assetUrl().
            if (str_ends_with($relativePath, '.css')) {
                $this->publishCssDependencies($source, $public, $relativePath, $mtime);
            }

            return '/themes/'.$dirName.'/'.$relativePath.'?v='.$mtime.'.'.filesize($source);
        }

        return null;
    }

    /**
     * Copies files referenced by a published stylesheet (icon fonts, backgrounds) next to it.
     * A ".deps" marker keeps this to one stat() per request once everything is in place.
     */
    private function publishCssDependencies(string $source, string $public, string $relativePath, int $mtime): void
    {
        $marker = $public.'.deps';
        if (is_file($marker) && filemtime($marker) === $mtime && @file_get_contents($marker) === (string) filesize($source)) {
            return;
        }

        $css = @file_get_contents($source);
        $resourcesRoot = realpath(substr($source, 0, -\strlen($relativePath)));
        if ($css === false || $resourcesRoot === false) {
            return;
        }

        $publicRoot = substr($public, 0, -\strlen($relativePath));
        preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $css, $matches);

        foreach (array_unique($matches[1]) as $reference) {
            $reference = preg_replace('/[?#].*$/', '', $reference) ?? '';
            if ($reference === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $reference) === 1) {
                continue;
            }

            $target = realpath(\dirname($source).'/'.$reference);
            if ($target === false || !is_file($target) || !str_starts_with($target, $resourcesRoot.\DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (preg_match('/\.(?:woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico)$/i', $target) !== 1) {
                continue;
            }

            $destination = $publicRoot.str_replace('\\', '/', substr($target, \strlen($resourcesRoot) + 1));
            $destinationDir = \dirname($destination);
            if ((!is_file($destination) || filesize($destination) !== filesize($target))
                && (is_dir($destinationDir) || @mkdir($destinationDir, 0775, true) || is_dir($destinationDir))) {
                @copy($target, $destination);
            }
        }

        @file_put_contents($marker, (string) filesize($source));
        @touch($marker, $mtime);
    }

    /**
     * Absolute path of the theme's preview image, or null. theme.json "screenshot"
     * (relative to the theme directory) wins; otherwise screenshot.{png,jpg,webp,svg}.
     */
    public function screenshotPath(ThemeDefinition $theme): ?string
    {
        $root = $this->themesDir.'/'.$theme->dirName;
        $candidates = $theme->screenshot !== null ? [$theme->screenshot] : [];
        foreach (['png', 'jpg', 'webp', 'svg'] as $ext) {
            $candidates[] = 'screenshot.'.$ext;
        }

        foreach ($candidates as $candidate) {
            $path = realpath($root.'/'.ltrim($candidate, '/'));
            $base = realpath($root);
            if ($path !== false && $base !== false && str_starts_with($path, $base.\DIRECTORY_SEPARATOR) && is_file($path)
                && preg_match('/\.(png|jpe?g|webp|svg)$/i', $path) === 1) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Absolute path to a theme's public asset, or null when it does not exist.
     */
    public function assetPath(ThemeDefinition $theme, string $relativePath): ?string
    {
        $path = $this->themesDir.'/'.$theme->dirName.'/Resources/'.ltrim($relativePath, '/');

        return is_file($path) ? $path : null;
    }

    public function themesDir(): string
    {
        return $this->themesDir;
    }
}
