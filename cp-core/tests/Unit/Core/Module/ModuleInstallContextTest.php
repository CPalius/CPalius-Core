<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleInstallContext;
use App\Core\Module\ModuleManifest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Regression guard for the exact bug that broke the 2.2.6 update on a real
 * site: a migration file whose leading "--" comment sits directly above its
 * CREATE TABLE, with no ";" between them, used to have BOTH silently dropped
 * by ModuleInstallContext::splitSqlStatements(), because it decided a chunk
 * was "just a comment" by checking whether the whole chunk started with "--"
 * rather than stripping comment lines from it.
 */
#[CoversClass(ModuleInstallContext::class)]
final class ModuleInstallContextTest extends TestCase
{
    private string $moduleDir;

    protected function setUp(): void
    {
        $this->moduleDir = sys_get_temp_dir().'/cpalius_test_module_'.bin2hex(random_bytes(6));
        mkdir($this->moduleDir.'/Resources/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->moduleDir);
    }

    public function testStatementDirectlyUnderALeadingCommentBlockIsNotDropped(): void
    {
        // Reproduces cp-content/modules/Whitepaper/Resources/migrations/001_whitepaper.sql
        // verbatim in shape: prose comment lines glued to the statement they
        // explain, no ";" in between.
        file_put_contents($this->moduleDir.'/Resources/migrations/001_test.sql', <<<'SQL'
            -- The unique key on (locale, slug) is the anchor contract: the slug is the
            -- fragment people have already linked to.
            CREATE TABLE IF NOT EXISTS cp_test_documents (
                id INTEGER PRIMARY KEY,
                locale TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cp_test_sections (
                id INTEGER PRIMARY KEY,
                locale TEXT NOT NULL
            );
            SQL);

        $connection = $this->connection();
        $context = $this->context($connection);

        $ran = $context->applyPendingSqlMigrations();

        self::assertSame(1, $ran, 'Migration file should count as one applied run.');
        self::assertTrue(
            $connection->createSchemaManager()->tablesExist(['cp_test_documents']),
            'cp_test_documents was never created — the statement under the leading comment was dropped, same as the real 2.2.6 incident.',
        );
        self::assertTrue(
            $connection->createSchemaManager()->tablesExist(['cp_test_sections']),
            'cp_test_sections (no leading comment) should exist regardless.',
        );
    }

    public function testCommentBetweenTwoStatementsDoesNotDropTheSecondOne(): void
    {
        // Reproduces cp-content/modules/Forum/Resources/migrations/20260916_forum_reputation_topic_url.sql's
        // shape: a comment sits between one statement's ";" and the next
        // statement's keyword — the SAME failure mode, just not on the first
        // statement in the file.
        file_put_contents($this->moduleDir.'/Resources/migrations/001_test.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS cp_test_a (id INTEGER PRIMARY KEY);

            -- Explains why the second table needs to exist.
            CREATE TABLE IF NOT EXISTS cp_test_b (id INTEGER PRIMARY KEY);
            SQL);

        $connection = $this->connection();
        $context = $this->context($connection);

        $context->applyPendingSqlMigrations();

        self::assertTrue($connection->createSchemaManager()->tablesExist(['cp_test_a']));
        self::assertTrue(
            $connection->createSchemaManager()->tablesExist(['cp_test_b']),
            'cp_test_b, preceded by a comment, should still be created.',
        );
    }

    public function testMigrationIsNotReRunOnceApplied(): void
    {
        file_put_contents(
            $this->moduleDir.'/Resources/migrations/001_test.sql',
            'CREATE TABLE IF NOT EXISTS cp_test_once (id INTEGER PRIMARY KEY);',
        );

        $connection = $this->connection();

        self::assertSame(1, $this->context($connection)->applyPendingSqlMigrations());
        self::assertSame(
            0,
            $this->context($connection)->applyPendingSqlMigrations(),
            'A second run must be a no-op — the applied-file ledger should have recorded it.',
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE cp_settings (id INTEGER PRIMARY KEY, setting_key TEXT, setting_value TEXT, module TEXT, updated_at TEXT)',
        );

        return $connection;
    }

    private function context(Connection $connection): ModuleInstallContext
    {
        $manifest = new ModuleManifest(
            dirName: 'TestModule',
            name: 'TestModule',
            version: '1.0.0',
            bundle: null,
        );

        return new ModuleInstallContext($connection, $manifest, $this->moduleDir, \dirname($this->moduleDir));
    }
}
