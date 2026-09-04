<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

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

        $themes = [];

        if (is_dir($this->themesDir)) {
            foreach (scandir($this->themesDir) ?: [] as $dirName) {
                if ($dirName === '.' || $dirName === '..') {
                    continue;
                }

                $themeDir = $this->themesDir.'/'.$dirName;

                if (!is_dir($themeDir)) {
                    continue;
                }

                $definition = ThemeDefinition::fromDirectory($themeDir);

                if ($definition !== null) {
                    $themes[$dirName] = $definition;
                }
            }
        }

        ksort($themes);

        return $this->themes = $themes;
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

        if ($configured !== '' && isset($themes[$configured])) {
            return $themes[$configured];
        }

        return $themes[self::FALLBACK_THEME] ?? reset($themes);
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

        try {
            $setting = $this->settingRepository->findOneBy(['settingKey' => self::ACTIVE_THEME_KEY]);

            if (!$setting instanceof Setting) {
                $setting = new Setting(self::ACTIVE_THEME_KEY, 'core');
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue($dirName);
            $this->entityManager->flush();
            $this->settingsRegistry->clearCache();
        } catch (Throwable $e) {
            return $e->getMessage();
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
