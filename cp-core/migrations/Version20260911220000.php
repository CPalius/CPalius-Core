<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T2.4: one pattern per (entity_type, bundle) driving automatic UrlAlias generation
 * (PathAliasGenerator). No data migration needed — absence of a row means "no
 * auto-generation for this bundle", the pre-existing manual-only behaviour.
 */
final class Version20260911220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Path alias patterns: cp_path_alias_patterns.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_path_alias_patterns'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_path_alias_patterns (
            id INT AUTO_INCREMENT NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            bundle VARCHAR(64) NOT NULL,
            pattern VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_path_alias_pattern_type_bundle (entity_type, bundle),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_path_alias_patterns'])) {
            $this->addSql('DROP TABLE cp_path_alias_patterns');
        }
    }
}
