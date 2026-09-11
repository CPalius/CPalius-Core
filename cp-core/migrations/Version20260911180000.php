<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T1.4: row-level access grants. subject_id is never NULL (empty string for
 * SUBJECT_ANY) so the composite unique constraint has simple semantics.
 */
final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Row-level access grants: cp_entity_access_grants.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_entity_access_grants'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_entity_access_grants (
            id INT AUTO_INCREMENT NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id INT NOT NULL,
            capability VARCHAR(150) NOT NULL,
            subject_type VARCHAR(10) NOT NULL,
            subject_id VARCHAR(64) NOT NULL,
            granted_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_grant_entity_capability (entity_type, entity_id, capability),
            INDEX idx_grant_subject (subject_type, subject_id),
            UNIQUE INDEX uniq_grant (entity_type, entity_id, capability, subject_type, subject_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_entity_access_grants'])) {
            $this->addSql('DROP TABLE cp_entity_access_grants');
        }
    }
}
