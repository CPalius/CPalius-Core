<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Core Field API: field definitions attached to content bundles (Node::type).
 * Values stay in Node::data JSON — this table only describes the fields.
 */
final class Version20260910020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Core Field API: cp_field_definitions.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_field_definitions'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_field_definitions (
            id INT AUTO_INCREMENT NOT NULL,
            bundle VARCHAR(64) NOT NULL,
            name VARCHAR(64) NOT NULL,
            type VARCHAR(32) NOT NULL,
            label VARCHAR(191) NOT NULL,
            help VARCHAR(500) DEFAULT NULL,
            required TINYINT(1) NOT NULL,
            cardinality INT NOT NULL,
            translatable TINYINT(1) NOT NULL,
            queryable TINYINT(1) NOT NULL,
            field_group VARCHAR(64) DEFAULT NULL,
            weight INT NOT NULL,
            settings JSON NOT NULL,
            view_capability VARCHAR(100) DEFAULT NULL,
            edit_capability VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_field_bundle_name (bundle, name),
            INDEX idx_field_bundle_weight (bundle, weight),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_field_definitions'])) {
            $this->addSql('DROP TABLE cp_field_definitions');
        }
    }
}
