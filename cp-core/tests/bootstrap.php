<?php

declare(strict_types=1);

/*
 * CPalius CMF — PHPUnit bootstrap (Phase 2).
 * Adapted for Pristine Root: autoloader under cp-includes/vendor; .env files live at project root.
 */

use Symfony\Component\Dotenv\Dotenv;

// cp-core/tests/bootstrap.php -> cp-core/tests -> cp-core -> project root
$projectDir = \dirname(__DIR__, 2);

require $projectDir.'/cp-includes/vendor/autoload.php';

/*
 * .env load order: .env -> .env.test -> .env.test.local
 * bootEnv() mirrors production env resolution (including compiled .env.local.php when present).
 */
if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($projectDir.'/.env');
}

/*
 * Fresh SQLite test database each run; stale test.db can hide schema regressions.
 * Schema creation stays in integration tests that need it; unit tests pay no DB cost.
 */
$testDatabase = $projectDir.'/cp-core/var/test.db';

if (is_file($testDatabase)) {
    @unlink($testDatabase);
}

$varDir = $projectDir.'/cp-core/var';
if (!is_dir($varDir)) {
    @mkdir($varDir, 0775, true);
}

/*
 * Drop every table in the SQL test database, once, before the suite starts.
 *
 * WHY THIS EXISTS
 * Integration tests reset themselves with SchemaTool::dropSchema($metadata)
 * followed by createSchema(). That only drops the tables the CURRENT mapping
 * knows about — so when an entity is deleted from the codebase, its table stays
 * behind forever. The leftovers are not merely clutter: their foreign keys
 * still point at live tables, so DROP TABLE users fails, createSchema then
 * reports "table already exists", and the entire integration suite dies.
 *
 * That is exactly what happened when Category/Tag were migrated into the
 * taxonomy: categories, tags, node_category, node_tag and forum_notifications
 * were orphaned and blocked all 48 integration tests. Purging by metadata can
 * never fix this, because the metadata is precisely what no longer mentions
 * them. Purging everything can, and mirrors the intent already stated above
 * for the SQLite file: every run starts from nothing.
 *
 * SAFETY
 * This deletes data, so it refuses to act unless the database name ends in
 * "_test" — the suffix Doctrine appends in the test environment (see
 * doctrine.yaml when@test: dbname_suffix). A misconfiguration that pointed the
 * test suite at the development database would otherwise be catastrophic and
 * silent. Failure to connect is NOT fatal: unit tests need no database, and
 * they must keep running on a machine with no SQL server at all.
 */
$purgeSqlTestDatabase = static function (string $databaseUrl): void {
    // SQLite is handled by the file deletion above; only server databases here.
    if (!preg_match('#^(?<driver>mysql|postgresql|postgres|pgsql)://#', $databaseUrl, $driver)) {
        return;
    }

    $parts = parse_url((string) preg_replace('#\?.*$#', '', $databaseUrl));

    if (!\is_array($parts) || !isset($parts['path'])) {
        return;
    }

    $name = ltrim($parts['path'], '/');
    $suffix = '_test'.($_ENV['TEST_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? '');

    // Doctrine appends the suffix at runtime; .env still names the dev database.
    if (!str_ends_with($name, $suffix)) {
        $name .= $suffix;
    }

    if (!str_contains($name, '_test')) {
        fwrite(\STDERR, sprintf(
            "[bootstrap] Refusing to purge \"%s\": a test database name must contain \"_test\".\n",
            $name,
        ));

        return;
    }

    $isMysql = $driver['driver'] === 'mysql';
    $dsn = $isMysql
        ? sprintf('mysql:host=%s;port=%d;dbname=%s', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 3306, $name)
        : sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 5432, $name);

    try {
        $pdo = new PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($isMysql) {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            if ($tables === []) {
                return;
            }

            // Constraint checks off: the drop order is otherwise unsolvable
            // once orphan tables reference live ones.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', (string) $table)));
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            return;
        }

        // PostgreSQL: CASCADE resolves the dependency order in one statement.
        $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    } catch (Throwable $e) {
        fwrite(\STDERR, sprintf("[bootstrap] Test database not purged (%s).\n", $e->getMessage()));
    }
};

$purgeSqlTestDatabase((string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? ''));

/*
 * Reset quarantine log so ModuleIsolationTest asserts only lines from the current run.
 */
$quarantineLog = $projectDir.'/cp-core/var/log/module_quarantine.log';

if (is_file($quarantineLog)) {
    @unlink($quarantineLog);
}
