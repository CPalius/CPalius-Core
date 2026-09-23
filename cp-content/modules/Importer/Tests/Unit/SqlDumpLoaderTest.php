<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Modules\Importer\Migration\DatabaseOptions;
use Modules\Importer\Source\SqlDumpLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlDumpLoader::class)]
#[CoversClass(DatabaseOptions::class)]
final class SqlDumpLoaderTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function testASqliteCompatibleDumpBecomesAReadableSource(): void
    {
        $this->file = sys_get_temp_dir().'/cp-sql-'.bin2hex(random_bytes(6)).'.sql';
        file_put_contents($this->file, <<<'SQL'
CREATE TABLE xf_node (node_id INTEGER PRIMARY KEY, title TEXT);
INSERT INTO xf_node (node_id, title) VALUES (1, 'Genel');
SQL);

        $app = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database = (new SqlDumpLoader($app))->open($this->file, 'xf_');

        self::assertTrue($database->hasTable('node'));
        self::assertSame('Genel', $database->connection()->fetchOne('SELECT title FROM xf_node WHERE node_id = 1'));
    }

    public function testConnectPrefersTheDumpOverRemoteFields(): void
    {
        $this->file = sys_get_temp_dir().'/cp-sql-'.bin2hex(random_bytes(6)).'.sql';
        file_put_contents($this->file, "CREATE TABLE xf_user (user_id INTEGER PRIMARY KEY);\nINSERT INTO xf_user (user_id) VALUES (7);\n");

        $app = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database = DatabaseOptions::connect([
            'sqlDump' => $this->file,
            'dbName' => '',
            'prefix' => 'xf_',
        ], 'xf_', $app);

        self::assertTrue($database->hasTable('user'));
    }

    public function testConnectRefusesAnEmptyForm(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Either upload a \.sql dump/');

        DatabaseOptions::connect(['dbName' => '', 'sqlDump' => ''], 'xf_');
    }
}
