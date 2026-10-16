<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recycle bin: cp_trash_items holds every deleted row verbatim, cp_trash_rules
 * the per-type on/off switch and retention. The "*" rule is the global default
 * and is seeded enabled with 30 days so a fresh install already protects deletes.
 */
final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates cp_trash_items and cp_trash_rules for the system-wide recycle bin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_trash_items (
                id INT AUTO_INCREMENT NOT NULL,
                batch_id VARCHAR(32) NOT NULL,
                resource_name VARCHAR(100) NOT NULL,
                entity_class VARCHAR(255) NOT NULL,
                table_name VARCHAR(128) NOT NULL,
                resource_id VARCHAR(128) DEFAULT NULL,
                label VARCHAR(255) NOT NULL,
                row_data JSON NOT NULL,
                deleted_by INT DEFAULT NULL,
                deleted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_trash_resource (resource_name),
                INDEX idx_trash_batch (batch_id),
                INDEX idx_trash_expires (expires_at),
                INDEX idx_trash_deleted_at (deleted_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cp_trash_rules (
                resource_name VARCHAR(100) NOT NULL,
                enabled TINYINT(1) DEFAULT 1 NOT NULL,
                retention_days INT DEFAULT 30 NOT NULL,
                PRIMARY KEY(resource_name)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql("INSERT INTO cp_trash_rules (resource_name, enabled, retention_days) VALUES ('*', 1, 30)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_trash_rules');
        $this->addSql('DROP TABLE cp_trash_items');
    }
}
