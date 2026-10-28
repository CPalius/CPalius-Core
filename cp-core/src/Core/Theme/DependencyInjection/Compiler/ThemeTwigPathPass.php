<?php

declare(strict_types=1);

namespace App\Core\Theme\DependencyInjection\Compiler;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeDiscovery;
use App\Core\Theme\ThemeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers every installed theme's views directory as a Twig namespace, plus a
 * shared "@Theme" namespace whose FIRST path is the active theme.
 *
 * Twig namespaces are resolved at compile time, so switching the active theme
 * requires a cache rebuild; the AACP theme screen triggers one after saving.
 */
final class ThemeTwigPathPass implements CompilerPassInterface
{
    /** Namespace every theme template can be reached through, active theme first. */
    public const SHARED_NAMESPACE = 'Theme';

    public const ACTIVE_THEME_PARAM = 'cpalius.active_theme';

    private const SETTING_KEY = 'core.active_theme';
    private const CONNECT_TIMEOUT = 2;

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $themesDir = $projectDir.'/cp-content/themes';

        $themes = ThemeDiscovery::scan($themesDir);
        $activeDirName = $this->resolveActiveTheme($container, $themes);

        $container->setParameter(self::ACTIVE_THEME_PARAM, $activeDirName);
        $container->setParameter('cpalius.themes', array_keys($themes));

        // Register the Twig global here: twig.yaml cannot reference the parameter
        // because it is merged before this pass sets it.
        if ($container->hasDefinition('twig')) {
            $container->getDefinition('twig')->addMethodCall('addGlobal', ['cp_active_theme', $activeDirName]);
        }

        $this->registerThemeTranslations($container, $themesDir.'/'.$activeDirName.'/Resources/translations');

        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }

        $loader = $container->getDefinition('twig.loader.native_filesystem');

        // The active theme is added FIRST so "@Theme/x.html.twig" resolves to it,
        // while other themes stay as fallbacks for partially overridden templates.
        $ordered = $themes;

        if (isset($ordered[$activeDirName])) {
            $active = $ordered[$activeDirName];
            unset($ordered[$activeDirName]);
            $ordered = [$activeDirName => $active] + $ordered;
        }

        foreach ($ordered as $dirName => $theme) {
            $viewsDir = $themesDir.'/'.$dirName.'/Resources/views';

            if (!is_dir($viewsDir)) {
                continue;
            }

            $loader->addMethodCall('addPath', [$viewsDir, self::SHARED_NAMESPACE]);
            $loader->addMethodCall('addPath', [$viewsDir, $theme->twigNamespace()]);
        }
    }

    /**
     * Adds the active theme's Resources/translations/<domain>.<locale>.yaml files to the translator.
     * They go FIRST in each locale's list: Symfony lets later files win, so module and core
     * (cp-content/translations) translations override a theme and a theme can never replace a core key.
     */
    private function registerThemeTranslations(ContainerBuilder $container, string $dir): void
    {
        if (!$container->hasDefinition('translator.default') || !$container->fileExists($dir)) {
            return;
        }

        $translator = $container->getDefinition('translator.default');
        $options = $translator->getArgument(4);

        if (!\is_array($options)) {
            return;
        }

        foreach (glob($dir.'/*.yaml') ?: [] as $file) {
            $parts = explode('.', basename($file));

            if (\count($parts) >= 3) {
                $locale = $parts[\count($parts) - 2];
                $options['resource_files'][$locale] = array_merge([$file], $options['resource_files'][$locale] ?? []);
            }
        }

        $options['scanned_directories'][] = $dir;
        $options['cache_vary']['scanned_directories'][] = $dir;
        $translator->replaceArgument(4, $options);
    }

    /**
     * Reads core.active_theme straight from the database: the container is being
     * built, so Doctrine and SettingsRegistry are not available yet.
     *
     * @param array<string, ThemeDefinition> $themes
     */
    private function resolveActiveTheme(ContainerBuilder $container, array $themes): string
    {
        $fallback = isset($themes[ThemeRegistry::FALLBACK_THEME])
            ? ThemeRegistry::FALLBACK_THEME
            : (string) (array_key_first($themes) ?? ThemeRegistry::FALLBACK_THEME);

        $stored = $this->readActiveThemeFromDatabase($container);

        if ($stored === null || !isset($themes[$stored])) {
            return $fallback;
        }

        // A theme whose required module is off would call Twig functions that no longer exist.
        $modulesDir = (string) $container->getParameter('kernel.project_dir').'/cp-content/modules';
        $bundles = (array) $container->getParameter('kernel.bundles');

        return $themes[$stored]->unmetModules($bundles, $modulesDir) === [] ? $stored : $fallback;
    }

    private function readActiveThemeFromDatabase(ContainerBuilder $container): ?string
    {
        $databaseUrl = $this->readEnv('DATABASE_URL');

        if ($databaseUrl === null) {
            return null;
        }

        try {
            $pdo = $this->connect($databaseUrl, $container);

            if (!$pdo instanceof \PDO) {
                return null;
            }

            $statement = $pdo->prepare('SELECT setting_value FROM cp_settings WHERE setting_key = ? LIMIT 1');
            $statement->execute([self::SETTING_KEY]);
            $value = $statement->fetchColumn();

            return \is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            // No database during cache:clear or CI: fall back to the default theme.
            error_log('[CPalius] ThemeTwigPathPass: active theme not read from database, using fallback ('.$e->getMessage().')');

            return null;
        }
    }

    private function connect(string $databaseUrl, ContainerBuilder $container): ?\PDO
    {
        $parts = parse_url($databaseUrl);

        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT];

        if (str_starts_with($scheme, 'sqlite')) {
            $path = preg_replace('#^sqlite3?:///#', '', $databaseUrl);

            if (!\is_string($path) || $path === '') {
                return null;
            }

            $path = str_replace('%kernel.project_dir%', (string) $container->getParameter('kernel.project_dir'), $path);

            return is_file($path) ? new \PDO('sqlite:'.$path, null, null, $options) : null;
        }

        $driver = match (true) {
            str_starts_with($scheme, 'mysql'), str_starts_with($scheme, 'mariadb') => 'mysql',
            str_starts_with($scheme, 'postgres'), str_starts_with($scheme, 'pgsql') => 'pgsql',
            default => null,
        };

        $database = trim((string) ($parts['path'] ?? ''), '/');

        if ($driver === null || $database === '') {
            return null;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s',
            $driver,
            $parts['host'] ?? '127.0.0.1',
            (int) ($parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432)),
            $database,
        );

        if ($driver === 'mysql') {
            $dsn .= ';charset=utf8mb4';
        }

        return new \PDO(
            $dsn,
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            $options,
        );
    }

    private function readEnv(string $name): ?string
    {
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (isset($bag[$name]) && \is_string($bag[$name]) && $bag[$name] !== '') {
                return $bag[$name];
            }
        }

        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
