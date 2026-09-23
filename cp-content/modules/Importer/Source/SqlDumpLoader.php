<?php

declare(strict_types=1);

namespace Modules\Importer\Source;

use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Loads a .sql / .sql.gz dump into a throwaway database and hands back a
 * ForeignDatabase pointed at it.
 *
 * WHY A TEMPORARY DATABASE
 * The XenForo (and MyBB / Joomla) migrations already speak SQL against a
 * ForeignDatabase. Turning a dump into that same shape means the mapping
 * does not care whether the operator uploaded a file or typed a host.
 *
 * The dump is imported once per file identity (path + size + mtime). A dry
 * run followed by apply reuses the same database instead of loading 200 MB
 * twice in one sitting.
 *
 * Large dumps still belong on a remote connection: a browser request that
 * feeds a 2 GB file through PHP will hit the time limit. This path is the
 * small-to-medium convenience.
 */
final class SqlDumpLoader
{
    public function __construct(
        private readonly Connection $app,
    ) {
    }

    public function open(string $path, string $prefix): ForeignDatabase
    {
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            throw new \RuntimeException(sprintf('SQL dump "%s" was not found on the server.', $path));
        }

        $name = 'cp_imp_'.substr(hash('sha256', $real.'|'.filesize($real).'|'.filemtime($real)), 0, 20);
        $source = $this->openWorkspace($name);

        if ($source->createSchemaManager()->listTableNames() === []) {
            $this->import($real, $source);
        }

        return ForeignDatabase::wrap($source, $prefix);
    }

    private function openWorkspace(string $name): Connection
    {
        $params = $this->app->getParams();
        $driver = (string) ($params['driver'] ?? 'pdo_mysql');

        if ($this->app->getDatabasePlatform() instanceof SqlitePlatform || str_contains($driver, 'sqlite')) {
            $file = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$name.'.sqlite';

            return DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $file,
            ]);
        }

        $this->createMysqlDatabase($name);

        $fromUrl = $this->paramsFromUrl((string) ($params['url'] ?? ''));

        return DriverManager::getConnection([
            'driver' => $driver !== '' ? $driver : 'pdo_mysql',
            'host' => (string) ($params['host'] ?? $fromUrl['host'] ?? '127.0.0.1'),
            'port' => (int) ($params['port'] ?? $fromUrl['port'] ?? 3306),
            'user' => (string) ($params['user'] ?? $fromUrl['user'] ?? ''),
            'password' => (string) ($params['password'] ?? $fromUrl['password'] ?? ''),
            'dbname' => $name,
            'charset' => (string) ($params['charset'] ?? 'utf8mb4'),
        ]);
    }

    /**
     * @return array{host?: string, port?: int, user?: string, password?: string}
     */
    private function paramsFromUrl(string $url): array
    {
        if ($url === '') {
            return [];
        }

        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return [];
        }

        $out = [];
        if (isset($parts['host'])) {
            $out['host'] = $parts['host'];
        }
        if (isset($parts['port'])) {
            $out['port'] = (int) $parts['port'];
        }
        if (isset($parts['user'])) {
            $out['user'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $out['password'] = rawurldecode($parts['pass']);
        }

        return $out;
    }

    private function createMysqlDatabase(string $name): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Refusing to create a temporary database with an unsafe name.');
        }

        try {
            $this->app->executeStatement('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Could not create a temporary database for the SQL dump. The MySQL user needs CREATE DATABASE, or import the dump into MySQL yourself and use the remote host fields. '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function import(string $path, Connection $target): void
    {
        $count = 0;

        try {
            foreach (SqlDumpReader::statements($path) as $statement) {
                $target->executeStatement($statement);
                ++$count;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('The SQL dump failed at statement %d: %s', $count + 1, $e->getMessage()), 0, $e);
        }

        if ($count === 0) {
            throw new \RuntimeException('That file did not contain any importable SQL statements. Export a .sql dump (phpMyAdmin / mysqldump), not a binary backup.');
        }
    }
}
