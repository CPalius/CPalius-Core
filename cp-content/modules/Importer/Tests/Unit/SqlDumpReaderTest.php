<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Source\SqlDumpReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlDumpReader::class)]
final class SqlDumpReaderTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function testItKeepsCreateAndInsertAndDropsUseAndCreateDatabase(): void
    {
        $this->file = sys_get_temp_dir().'/cp-sql-'.bin2hex(random_bytes(6)).'.sql';
        file_put_contents($this->file, <<<'SQL'
-- phpMyAdmin
CREATE DATABASE IF NOT EXISTS `xenforo`;
USE `xenforo`;
CREATE TABLE `xf_node` (
  `node_id` int NOT NULL,
  `title` varchar(50) NOT NULL
);
INSERT INTO `xf_node` (`node_id`, `title`) VALUES (1, 'Genel');
GRANT ALL ON *.* TO 'evil'@'%';
SQL);

        $statements = iterator_to_array(SqlDumpReader::statements($this->file), false);

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE', $statements[0]);
        self::assertStringStartsWith('INSERT INTO', $statements[1]);
    }

    public function testItUnwrapsMysqlVersionComments(): void
    {
        $this->file = sys_get_temp_dir().'/cp-sql-'.bin2hex(random_bytes(6)).'.sql';
        file_put_contents($this->file, "/*!40101 SET NAMES utf8mb4 */;\nCREATE TABLE xf_user (user_id INT);\n");

        $statements = iterator_to_array(SqlDumpReader::statements($this->file), false);

        self::assertSame('SET NAMES utf8mb4', $statements[0]);
        self::assertSame('CREATE TABLE xf_user (user_id INT)', $statements[1]);
    }
}
