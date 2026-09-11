<?php

declare(strict_types=1);

namespace App\Core\Theme\DependencyInjection\Compiler;

use App\Core\Theme\ThemeDefinition;
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
    private const FALLBACK_THEME = 'cpalius-website';
    private const CONNECT_TIMEOUT = 2;

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $themesDir = $projectDir.'/cp-content/themes';

        $themes = $this->discoverThemes($themesDir);
        $activeDirName = $this->resolveActiveTheme($container, $themes);

        $container->setParameter(self::ACTIVE_THEME_PARAM, $activeDirName);
        $container->setParameter('cpalius.themes', array_keys($themes));

        // Register the Twig global here: twig.yaml cannot reference the parameter
        // because it is merged before this pass sets it.
        if ($container->hasDefinition('twig')) {
            $container->getDefinition('twig')->addMethodCall('addGlobal', ['cp_active_theme', $activeDirName]);
        }

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
     * @return array<string, ThemeDefinition>
     */
    private function discoverThemes(string $themesDir): array
    {
        if (!is_dir($themesDir)) {
            return [];
        }

        $themes = [];

        foreach (scandir($themesDir) ?: [] as $dirName) {
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

    /**
     * Reads core.active_theme straight from the database: the container is being
     * built, so Doctrine and SettingsRegistry are not available yet.
     *
     * @param array<string, ThemeDefinition> $themes
     */
    private function resolveActiveTheme(ContainerBuilder $container, array $themes): string
    {
        $fallback = isset($themes[self::FALLBACK_THEME])
            ? self::FALLBACK_THEME
            : (string) (array_key_first($themes) ?? self::FALLBACK_THEME);

        $stored = $this->readActiveThemeFromDatabase($container);

        return $stored !== null && isset($themes[$stored]) ? $stored : $fallback;
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
        } catch (\Throwable) {
            // No database during cache:clear or CI: fall back silently.
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
