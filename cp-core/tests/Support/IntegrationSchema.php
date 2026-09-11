<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Resets the test database to exactly the current mapping.
 *
 * WHY THIS REPLACED SchemaTool::dropSchema()
 * Fifteen integration tests each carried their own copy of
 *
 *     $metadata = $em->getMetadataFactory()->getAllMetadata();
 *     $tool->dropSchema($metadata);
 *     $tool->createSchema($metadata);
 *
 * and that pattern is unsound in two separate ways:
 *
 *   1. dropSchema() only drops tables the CURRENT mapping knows about. Delete an
 *      entity — as the Category/Tag move into the taxonomy did — and its table
 *      survives forever, foreign keys included. Those keys then block the drop
 *      of tables that ARE mapped, so createSchema() dies on "table already
 *      exists".
 *   2. Even with no orphans, MySQL refuses drops in an order that violates
 *      referential integrity. dropSchema() emits its statements as a best
 *      effort, so a failure part-way leaves the schema half-dropped. The next
 *      createSchema() then fails on whichever table survived — and because
 *      PHPUnit reorders previously failed tests to run first, the surviving
 *      table differs between runs. That is precisely the shape of the
 *      intermittent failures this codebase was seeing.
 *
 * Dropping every table in the database, with referential integrity suspended,
 * removes both failure modes: the reset no longer depends on what the mapping
 * remembers, nor on finding a valid drop order.
 *
 * Only ever point this at a database you are willing to lose; the suite's
 * bootstrap enforces the "_test" suffix before anything reaches this class.
 */
final class IntegrationSchema
{
    /**
     * Drops everything, then recreates the schema from the current mapping.
     */
    public static function reset(EntityManagerInterface $em): void
    {
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        if ($metadata === []) {
            return;
        }

        self::dropEveryTable($em->getConnection());

        (new SchemaTool($em))->createSchema($metadata);
    }

    private static function dropEveryTable(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $schemaManager = $connection->createSchemaManager();

        if ($platform instanceof PostgreSQLPlatform) {
            // CASCADE resolves the dependency order in a single statement.
            $connection->executeStatement('DROP SCHEMA public CASCADE');
            $connection->executeStatement('CREATE SCHEMA public');

            return;
        }

        $tables = $schemaManager->listTableNames();

        if ($tables === []) {
            return;
        }

        $isMysql = $platform instanceof AbstractMySQLPlatform;
        $isSqlite = $platform instanceof SqlitePlatform;

        if ($isMysql) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($isSqlite) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($tables as $table) {
                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $platform->quoteSingleIdentifier($table),
                ));
            }
        } finally {
            // Restored in a finally block: leaving integrity checks off would
            // silently weaken every assertion made by the test that follows.
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($isSqlite) {
                $connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        }
    }
}
