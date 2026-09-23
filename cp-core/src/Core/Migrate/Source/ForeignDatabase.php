<?php

declare(strict_types=1);

namespace App\Core\Migrate\Source;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A connection to somebody else's database, plus the table prefix it uses.
 *
 * WHY THE PREFIX IS VALIDATED AND THE REST IS PARAMETERISED
 * Everything an import reads is eventually a SQL query, and exactly one part
 * of that query cannot be a bound parameter: the table name. Forum packages
 * let the installer choose a prefix — "smf_", "xf_", "phpbb3_" — so the prefix
 * has to be pasted into the SQL text, and it arrives from a form. It is
 * therefore checked against a strict identifier pattern here, once, rather
 * than trusted at each of the dozen places that build a query. Column and
 * table names come from driver code, never from input; only values are bound.
 *
 * The connection is READ-ONLY by intent. Nothing in the importer writes to the
 * source, and the operator should be given credentials that cannot — a
 * migration is the worst possible time to discover that a mapping bug has
 * modified the site being migrated away from.
 */
final class ForeignDatabase
{
    /**
     * Prefixes are identifiers: letters, digits and underscore, and they may
     * not start with a digit. Anything else is refused rather than escaped,
     * because there is no legitimate prefix this pattern excludes.
     */
    private const PREFIX_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private ?Connection $connection = null;

    public function __construct(
        private readonly string $driver,
        private readonly string $host,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly string $prefix = '',
        private readonly int $port = 3306,
        private readonly string $charset = 'utf8mb4',
    ) {
        if ($prefix !== '' && preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid table prefix. Use letters, digits and underscore only.', $prefix));
        }
    }

    /**
     * Builds one from the options an import screen collected.
     *
     * @param array<string, string> $options
     */
    public static function fromOptions(array $options, string $defaultPrefix = ''): self
    {
        $dsn = trim($options['dbHost'] ?? '');
        $port = 3306;

        // "host:port" is what people paste, because that is what their hosting
        // panel shows them.
        if (str_contains($dsn, ':')) {
            [$dsn, $rawPort] = explode(':', $dsn, 2);

            if (is_numeric($rawPort)) {
                $port = (int) $rawPort;
            }
        }

        return new self(
            driver: 'pdo_mysql',
            host: $dsn !== '' ? $dsn : '127.0.0.1',
            database: trim($options['dbName'] ?? ''),
            user: trim($options['dbUser'] ?? ''),
            password: $options['dbPassword'] ?? '',
            prefix: trim($options['prefix'] ?? $defaultPrefix),
            port: $port,
        );
    }

    /**
     * Wraps an already-open connection, which is what tests and any caller
     * that already has one use.
     */
    public static function wrap(Connection $connection, string $prefix = ''): self
    {
        $database = new self('pdo_sqlite', '', '', '', '', $prefix);
        $database->connection = $connection;

        return $database;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The source table name, prefix included.
     */
    public function table(string $name): string
    {
        if (preg_match(self::PREFIX_PATTERN, $name) !== 1) {
            // Table names come from driver code, so this failing means a bug
            // here rather than bad input — but it is the last line before a
            // name reaches the SQL text, so it is checked anyway.
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid table name.', $name));
        }

        return $this->prefix.$name;
    }

    public function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if ($this->database === '') {
            throw new \RuntimeException('No source database was given. Fill in the database name.');
        }

        try {
            $connection = DriverManager::getConnection([
                'driver' => $this->driver,
                'host' => $this->host,
                'port' => $this->port,
                'dbname' => $this->database,
                'user' => $this->user,
                'password' => $this->password,
                'charset' => $this->charset,
            ]);

            // DriverManager is lazy; force the handshake so a wrong password
            // is reported when the operator presses the button rather than
            // part-way through the first migration.
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Could not connect to the source database: %s', $e->getMessage()), 0, $e);
        }

        return $this->connection = $connection;
    }

    /**
     * Whether a table is there, used to tell "wrong prefix" from "empty forum"
     * before an import reports thousands of nothing.
     */
    public function hasTable(string $name): bool
    {
        try {
            return $this->connection()->createSchemaManager()->tablesExist([$this->table($name)]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $tables
     *
     * @throws \RuntimeException naming the first table that is not there
     */
    public function assertTables(array $tables): void
    {
        foreach ($tables as $table) {
            if (!$this->hasTable($table)) {
                throw new \RuntimeException($this->missingTableMessage($table));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        try {
            return $this->connection()->createSchemaManager()->listTableNames();
        } catch (\Throwable) {
            return [];
        }
    }

    private function missingTableMessage(string $table): string
    {
        $wanted = $this->table($table);
        $hint = $this->prefix === '' ? ' (none given)' : sprintf(' ("%s")', $this->prefix);
        $message = sprintf('Table "%s" is not in that database. Check the table prefix%s.', $wanted, $hint);
        $names = $this->tableNames();

        if ($names === []) {
            return $message.' The database is empty — the host/name is probably not the old site, or upload a .sql dump instead of connecting remotely.';
        }

        $matches = [];
        foreach ($names as $name) {
            if (str_ends_with(strtolower($name), strtolower($table))) {
                $prefix = substr($name, 0, -\strlen($table));
                $matches[] = sprintf('%s (prefix "%s")', $name, $prefix);
            }
        }

        if ($matches !== []) {
            return $message.' Similar tables found: '.implode(', ', $matches).'.';
        }

        $sample = \array_slice($names, 0, 10);

        return $message.' Tables in that database: '.implode(', ', $sample).(\count($names) > 10 ? '…' : '').'.';
    }
}
