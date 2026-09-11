<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content Moderation: editorial workflow place on nodes, separate from publication.
 */
final class Version20260910040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Content moderation: nodes.moderation_state.';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('nodes');
        if (isset($columns['moderation_state'])) {
            return;
        }

        $this->addSql('ALTER TABLE nodes ADD moderation_state VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_node_moderation_state ON nodes (moderation_state)');
    }

    public function down(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('nodes');
        if (!isset($columns['moderation_state'])) {
            return;
        }

        $this->addSql('DROP INDEX idx_node_moderation_state ON nodes');
        $this->addSql('ALTER TABLE nodes DROP moderation_state');
    }
}
