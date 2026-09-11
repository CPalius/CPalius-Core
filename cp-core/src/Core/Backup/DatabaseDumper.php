<?php

declare(strict_types=1);

namespace App\Core\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Portable PHP dump so AACP and CLI work on Windows without mysqldump on PATH.
 */
final class DatabaseDumper
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function dumpToGzip(string $targetPath): void
    {
        if (!\function_exists('gzopen')) {
            throw new BackupException('The zlib extension is required to write gzipped SQL dumps.');
        }

        $handle = gzopen($targetPath, 'wb9');
        if ($handle === false) {
            throw new BackupException(sprintf('Could not write dump file "%s".', $targetPath));
        }

        try {
            $this->writeDump($handle);
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function writeDump($handle): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $generatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->write($handle, "-- CPalius CMF database dump\n");
        $this->write($handle, '-- Generated at '.$generatedAt."\n");
        $this->write($handle, '-- Platform: '.$platform::class."\n\n");

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
            $this->dumpMysql($handle);
            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->write($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
            $this->dumpSqlite($handle);
            $this->write($handle, "COMMIT;\nPRAGMA foreign_keys=ON;\n");

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->write($handle, "-- Restore schema with doctrine:migrations:migrate before applying INSERTs.\n");
            $this->write($handle, "SET session_replication_role = replica;\n\n");
            $this->dumpGenericInserts($handle);
            $this->write($handle, "SET session_replication_role = DEFAULT;\n");

            return;
        }

        $this->write($handle, "-- Restore schema with doctrine:migrations:migrate before applying INSERTs.\n\n");
        $this->dumpGenericInserts($handle);
    }

    /**
     * @param resource $handle
     */
    private function dumpMysql($handle): void
    {
        foreach ($this->tableNames() as $table) {
            $quoted = $this->quoteName($table);
            $create = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$quoted);
            if ($create === false) {
                continue;
            }

            $ddl = $create['Create Table'] ?? $create['Create View'] ?? null;
            if (!\is_string($ddl) || $ddl === '') {
                continue;
            }

            $this->write($handle, 'DROP TABLE IF EXISTS '.$quoted.";\n");
            $this->write($handle, $ddl.";\n\n");
            $this->writeInserts($handle, $table, $quoted);
            $this->write($handle, "\n");
        }
    }

    /**
     * @param resource $handle
     */
    private function dumpSqlite($handle): void
    {
        $statements = $this->connection->fetchAllAssociative(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        foreach ($statements as $row) {
            $table = (string) ($row['name'] ?? '');
            $sql = (string) ($row['sql'] ?? '');
            if (!$this->isSafeIdentifier($table) || $sql === '') {
                continue;
            }

            $quoted = $this->quoteName($table);
            $this->write($handle, 'DROP TABLE IF EXISTS '.$quoted.";\n");
            $this->write($handle, $sql.";\n\n");
            $this->writeInserts($handle, $table, $quoted);
            $this->write($handle, "\n");
        }
    }

    /**
     * @param resource $handle
     */
    private function dumpGenericInserts($handle): void
    {
        foreach ($this->tableNames() as $table) {
            $quoted = $this->quoteName($table);
            $this->write($handle, '-- Table '.$quoted."\n");
            $this->writeInserts($handle, $table, $quoted);
            $this->write($handle, "\n");
        }
    }

    /**
     * @param resource $handle
     */
    private function writeInserts($handle, string $table, string $quotedTable): void
    {
        $result = $this->connection->executeQuery('SELECT * FROM '.$quotedTable);
        $columnSql = null;
        $batch = [];

        while (($row = $result->fetchAssociative()) !== false) {
            if ($columnSql === null) {
                $quotedColumns = [];
                foreach (array_keys($row) as $column) {
                    if (!$this->isSafeIdentifier((string) $column)) {
                        throw new BackupException(sprintf('Unsafe column name on table "%s".', $table));
                    }
                    $quotedColumns[] = $this->quoteName((string) $column);
                }
                $columnSql = implode(', ', $quotedColumns);
            }

            $batch[] = '('.$this->rowLiterals($row).')';
            if (\count($batch) >= self::BATCH_SIZE) {
                $this->flushInsert($handle, $quotedTable, $columnSql, $batch);
                $batch = [];
            }
        }

        if ($batch !== [] && $columnSql !== null) {
            $this->flushInsert($handle, $quotedTable, $columnSql, $batch);
        }
    }

    /**
     * @param resource     $handle
     * @param list<string> $values
     */
    private function flushInsert($handle, string $quotedTable, string $columnSql, array $values): void
    {
        $this->write($handle, 'INSERT INTO '.$quotedTable.' ('.$columnSql.') VALUES '."\n");
        $this->write($handle, implode(",\n", $values).";\n");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowLiterals(array $row): string
    {
        $literals = [];
        foreach ($row as $value) {
            $literals[] = $this->sqlLiteral($value);
        }

        return implode(', ', $literals);
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        if (!\is_string($value) && !is_numeric($value)) {
            $value = (string) $value;
        }

        $string = (string) $value;
        $isUtf8Text = $string === '' || (!str_contains($string, "\0") && mb_check_encoding($string, 'UTF-8'));
        if ($isUtf8Text) {
            return $this->quoteString($string);
        }

        $hex = bin2hex($string);
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return "X'".$hex."'";
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return "decode('".$hex."', 'hex')";
        }

        return '0x'.$hex;
    }

    private function quoteString(string $value): string
    {
        return $this->connection->quote($value);
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $names = $this->connection->createSchemaManager()->listTableNames();
        $safe = [];
        foreach ($names as $name) {
            if ($this->isSafeIdentifier($name)) {
                $safe[] = $name;
            }
        }

        sort($safe);

        return $safe;
    }

    private function quoteName(string $name): string
    {
        if (!$this->isSafeIdentifier($name)) {
            throw new BackupException(sprintf('Unsafe SQL identifier "%s".', $name));
        }

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            return '`'.$name.'`';
        }

        return '"'.$name.'"';
    }

    private function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $sql): void
    {
        if (gzwrite($handle, $sql) === false) {
            throw new BackupException('Writing the SQL dump failed.');
        }
    }
}
