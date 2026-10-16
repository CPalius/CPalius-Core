<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public content lists filter cp_nodes by type, locale, status and deleted_at and sort by
 * published_at. The older (type, locale, status, published_at) index cannot serve the
 * soft-delete filter, so MySQL read every row of the type; this one is index-only for the list.
 */
final class Version20261016210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds (type, locale, status, deleted_at, published_at) index on cp_nodes.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('cp_nodes')->hasIndex('idx_node_live_list')) {
            $this->addSql('CREATE INDEX idx_node_live_list ON cp_nodes (type, locale, status, deleted_at, published_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('cp_nodes')->hasIndex('idx_node_live_list')) {
            $this->addSql('DROP INDEX idx_node_live_list ON cp_nodes');
        }
    }
}
