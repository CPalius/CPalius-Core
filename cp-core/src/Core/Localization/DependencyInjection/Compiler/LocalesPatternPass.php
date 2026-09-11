<?php

declare(strict_types=1);

namespace App\Core\Localization\DependencyInjection\Compiler;

use PDO;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compile-time locale pattern for route requirements (%cpalius.locales_pattern%). Uses raw PDO, not Doctrine.
 * DB failure falls back to .env then ['tr','en'] — never aborts compile. Adding a locale needs a cache rebuild.
 */
final class LocalesPatternPass implements CompilerPassInterface
{
    public const PARAM_LOCALES = 'cpalius.locales';
    public const PARAM_DEFAULT = 'cpalius.default_locale';
    public const PARAM_PATTERN = 'cpalius.locales_pattern';

    private const HARD_FALLBACK = ['tr', 'en'];
    private const CONNECT_TIMEOUT = 2;

    public function process(ContainerBuilder $container): void
    {
        [$codes, $default] = $this->resolveFromEnvironment($container);

        $fromDatabase = $this->tryLoadFromDatabase($container);

        if ($fromDatabase !== null) {
            [$codes, $default] = $fromDatabase;
        }

        if ($codes === []) {
            $codes = self::HARD_FALLBACK;
        }

        if (!\in_array($default, $codes, true)) {
            $default = $codes[0];
        }

        $container->setParameter(self::PARAM_LOCALES, $codes);
        $container->setParameter(self::PARAM_DEFAULT, $default);
        $container->setParameter(self::PARAM_PATTERN, implode('|', array_map(preg_quote(...), $codes)));
    }

    /**
     * @return array{list<string>, string}
     */
    private function resolveFromEnvironment(ContainerBuilder $container): array
    {
        $codes = $this->normalizeCodes(explode(',', $this->readEnv('CPALIUS_LOCALES') ?? ''));

        if ($codes === []) {
            $codes = self::HARD_FALLBACK;
        }

        $default = strtolower(trim($this->readEnv('CPALIUS_DEFAULT_LOCALE') ?? ''));

        if ($default === '' && $container->hasParameter('kernel.default_locale')) {
            $default = strtolower(trim((string) $container->getParameter('kernel.default_locale')));
        }

        if (preg_match('/^[a-z]{2,5}$/', $default) !== 1) {
            $default = $codes[0];
        }

        return [$codes, $default];
    }

    /**
     * @return array{list<string>, string}|null null when DB is unreadable (fallback signal, not an error)
     */
    private function tryLoadFromDatabase(ContainerBuilder $container): ?array
    {
        $dsnUrl = $this->readEnv('DATABASE_URL');

        if ($dsnUrl === null || $dsnUrl === '') {
            return null;
        }

        try {
            $pdo = $this->connect($dsnUrl, $container);

            if (!$pdo instanceof \PDO) {
                return null;
            }

            $statement = $pdo->query('SELECT code, is_default FROM cp_locales WHERE is_active = 1 ORDER BY sort_order ASC');

            if ($statement === false) {
                return null;
            }

            $codes = [];
            $default = '';

            /** @var array<string, mixed> $row */
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $code = strtolower(trim((string) ($row['code'] ?? '')));

                if (preg_match('/^[a-z]{2,5}$/', $code) !== 1 || \in_array($code, $codes, true)) {
                    continue;
                }

                $codes[] = $code;

                if ((bool) ($row['is_default'] ?? false) && $default === '') {
                    $default = $code;
                }
            }

            if ($codes === []) {
                return null;
            }

            return [$codes, $default !== '' ? $default : $codes[0]];
        } catch (\Throwable) {
            // No DB / table / driver: fall back to .env.
            return null;
        }
    }

    /**
     * Turn DATABASE_URL (mysql/postgres/sqlite) into a raw PDO connection.
     */
    private function connect(string $databaseUrl, ContainerBuilder $container): ?\PDO
    {
        $parts = parse_url($databaseUrl);

        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
        ];

        if (str_starts_with($scheme, 'sqlite')) {
            $path = $this->resolveSqlitePath($databaseUrl, $container);

            return $path !== null && is_file($path) ? new \PDO('sqlite:'.$path, null, null, $options) : null;
        }

        $driver = match (true) {
            str_starts_with($scheme, 'mysql'), str_starts_with($scheme, 'mariadb') => 'mysql',
            str_starts_with($scheme, 'postgres'), str_starts_with($scheme, 'pgsql') => 'pgsql',
            default => null,
        };

        if ($driver === null) {
            return null;
        }

        $database = trim((string) ($parts['path'] ?? ''), '/');

        if ($database === '') {
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

    /**
     * Expand sqlite:///%kernel.project_dir%/... to a real filesystem path.
     */
    private function resolveSqlitePath(string $databaseUrl, ContainerBuilder $container): ?string
    {
        $path = preg_replace('#^sqlite3?:///#', '', $databaseUrl);

        if (!\is_string($path) || $path === '') {
            return null;
        }

        if ($container->hasParameter('kernel.project_dir')) {
            $path = str_replace('%kernel.project_dir%', (string) $container->getParameter('kernel.project_dir'), $path);
        }

        return $path;
    }

    /**
     * Dotenv fills $_ENV/$_SERVER before compile, so placeholders are not needed here.
     */
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

    /**
     * @param list<string> $raw
     *
     * @return list<string>
     */
    private function normalizeCodes(array $raw): array
    {
        $codes = [];

        foreach ($raw as $candidate) {
            $code = strtolower(trim($candidate));

            if (preg_match('/^[a-z]{2,5}$/', $code) === 1 && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
