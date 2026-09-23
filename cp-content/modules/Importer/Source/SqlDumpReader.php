<?php

declare(strict_types=1);

namespace Modules\Importer\Source;

/**
 * Splits a MySQL dump into executable statements.
 *
 * XenForo / phpMyAdmin dumps are CREATE TABLE + INSERT, sometimes wrapped in
 * MySQL versioned comments. CREATE DATABASE, USE and GRANT are dropped so the
 * dump lands in the temporary database we opened, not the one named in the file.
 */
final class SqlDumpReader
{
    /**
     * @return \Generator<int, string>
     */
    public static function statements(string $path): \Generator
    {
        $handle = self::open($path);

        try {
            $buffer = '';
            $inBlockComment = false;

            while (($line = fgets($handle)) !== false) {
                if ($inBlockComment) {
                    $end = strpos($line, '*/');
                    if ($end === false) {
                        continue;
                    }
                    $line = substr($line, $end + 2);
                    $inBlockComment = false;
                }

                $trim = ltrim($line);
                if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                    continue;
                }

                if (str_starts_with($trim, '/*') && !str_starts_with($trim, '/*!')) {
                    if (!str_contains($line, '*/')) {
                        $inBlockComment = true;
                    }
                    continue;
                }

                $buffer .= $line;
                if (!str_ends_with(rtrim($buffer), ';')) {
                    continue;
                }

                $statement = self::normalise(trim($buffer));
                $buffer = '';

                if ($statement !== '' && self::isAllowed($statement)) {
                    yield $statement;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $header = (string) file_get_contents($path, false, null, 0, 2);
        $wrapper = $header === "\x1f\x8b" ? 'compress.zlib://' : '';
        $handle = fopen($wrapper.$path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not read the SQL dump "%s".', basename($path)));
        }

        return $handle;
    }

    private static function normalise(string $statement): string
    {
        $statement = rtrim($statement, " \t\n\r\0\x0B;");

        if (preg_match('/^\/\*!\d+\s+(.*)\*\/$/s', $statement, $match) === 1) {
            $statement = rtrim($match[1], " \t\n\r\0\x0B;");
        }

        return trim($statement);
    }

    private static function isAllowed(string $statement): bool
    {
        $upper = strtoupper($statement);

        if (preg_match('/^(CREATE\s+DATABASE|DROP\s+DATABASE|USE\s+|CREATE\s+USER|GRANT\s+|REVOKE\s+|FLUSH\s+|SET\s+GLOBAL)/', $upper) === 1) {
            return false;
        }

        return preg_match('/^(CREATE|INSERT|REPLACE|ALTER|DROP\s+TABLE|DROP\s+INDEX|LOCK|UNLOCK|SET\s+|TRUNCATE)/', $upper) === 1;
    }
}
