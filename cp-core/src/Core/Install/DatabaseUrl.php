<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Builds and probes the MySQL DSN the wizard writes into DATABASE_URL.
 *
 * Credentials stay out of the DSN exception text: PDO sometimes echoes the
 * DSN, and a shared-host error page must not become a copy of the password.
 */
final class DatabaseUrl
{
    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $input
     *
     * @return array{url: string, serverVersion: string}
     */
    public function probe(array $input): array
    {
        $host = $input['host'];
        $port = $input['port'];
        $name = $input['name'];
        $user = $input['user'];
        $password = $input['password'];

        $this->assertParts($host, $port, $name, $user);

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException($this->safeConnectionMessage($e), 0, $e);
        }

        $banner = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $serverVersion = self::serverVersionFromBanner($banner);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        if (!self::isInstallableDatabase(\is_array($tables) ? $tables : [])) {
            throw new \RuntimeException('The database is not empty. Create a new empty database and try again.');
        }

        return [
            'url' => self::toDatabaseUrl($host, $port, $name, $user, $password, $serverVersion),
            'serverVersion' => $serverVersion,
        ];
    }

    /**
     * A failed wizard leaves only Doctrine's bookkeeping table. That database
     * can be continued; anything else is someone else's data.
     *
     * @param list<mixed> $tables
     */
    public static function isInstallableDatabase(array $tables): bool
    {
        foreach ($tables as $table) {
            if (strtolower((string) $table) !== 'doctrine_migration_versions') {
                return false;
            }
        }

        return true;
    }

    public static function serverVersionFromBanner(string $banner): string
    {
        $match = [];
        $number = '8.0.32';
        if (preg_match('/(\d+\.\d+\.\d+)/', $banner, $match) === 1) {
            $number = $match[1];
        }

        if (stripos($banner, 'mariadb') !== false) {
            return 'mariadb-'.$number;
        }

        return $number;
    }

    public static function toDatabaseUrl(string $host, int $port, string $name, string $user, string $password, string $serverVersion): string
    {
        return sprintf(
            'mysql://%s:%s@%s:%d/%s?serverVersion=%s&charset=utf8mb4',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            $name,
            rawurlencode($serverVersion),
        );
    }

    private function assertParts(string $host, int $port, string $name, string $user): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException('Database host is not valid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Database port is not valid.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Database name is not valid.');
        }

        if ($user === '' || preg_match('/[\x00\r\n]/', $user) === 1) {
            throw new \InvalidArgumentException('Database user is not valid.');
        }
    }

    private function safeConnectionMessage(\PDOException $e): string
    {
        $sqlState = $e->getCode();
        $state = \is_string($sqlState) ? $sqlState : '';

        return match (true) {
            str_contains($e->getMessage(), '1044'), str_contains($e->getMessage(), '1045') => 'Database user or password was rejected.',
            str_contains($e->getMessage(), '1049') => 'That database does not exist. Create an empty database in the hosting panel first.',
            str_contains($e->getMessage(), '2002'), str_contains($e->getMessage(), '2003') => 'The database server could not be reached. Check the host and port.',
            default => 'Could not connect to the database'.($state !== '' ? ' ('.$state.')' : '').'.',
        };
    }
}
