<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Node revision history (Manifesto Law 3.1 — "SEO & Revision").
 */
final class Version20260910030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Node revision history: cp_node_revisions.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_node_revisions'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_node_revisions (
            id INT AUTO_INCREMENT NOT NULL,
            node_id INT NOT NULL,
            author_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            snapshot JSON NOT NULL,
            snapshot_hash VARCHAR(64) NOT NULL,
            log_message VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_node_revision_node_created (node_id, created_at),
            INDEX IDX_AF523AA0F675F31B (author_id),
            CONSTRAINT fk_node_revision_node FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE,
            CONSTRAINT fk_node_revision_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_node_revisions'])) {
            $this->addSql('DROP TABLE cp_node_revisions');
        }
    }
}
