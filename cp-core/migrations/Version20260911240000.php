<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T2.5: named text formats. Absence of a row means the YAML catalog
 * (cp-core/config/text_formats.yaml) is live — same "zero-config = defaults"
 * convention as EntityDisplay.
 */
final class Version20260911240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Text formats: cp_text_formats.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_text_formats'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_text_formats (
            id INT AUTO_INCREMENT NOT NULL,
            machine_name VARCHAR(32) NOT NULL,
            label VARCHAR(191) NOT NULL,
            description VARCHAR(500) NOT NULL,
            wysiwyg TINYINT(1) NOT NULL,
            filters JSON NOT NULL,
            locked TINYINT(1) NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_text_format_machine_name (machine_name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_text_formats'])) {
            $this->addSql('DROP TABLE cp_text_formats');
        }
    }
}
