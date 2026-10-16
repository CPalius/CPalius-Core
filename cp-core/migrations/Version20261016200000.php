<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newest-member lookups (forum front page, member lists) order cp_users by created_at,
 * optionally filtered by status. With no index on created_at MySQL sorts the whole
 * table for every such request; with these two it reads the first rows of the index.
 * Measured with cp:perf:probe: two 2.4–2.8 ms statements per forum page on a tiny table,
 * growing with the row count.
 */
final class Version20261016200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds (status, created_at) and (created_at) indexes on cp_users.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cp_users');

        if (!$table->hasIndex('idx_user_status_created')) {
            $this->addSql('CREATE INDEX idx_user_status_created ON cp_users (status, created_at)');
        }
        if (!$table->hasIndex('idx_user_created')) {
            $this->addSql('CREATE INDEX idx_user_created ON cp_users (created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('cp_users');

        if ($table->hasIndex('idx_user_created')) {
            $this->addSql('DROP INDEX idx_user_created ON cp_users');
        }
        if ($table->hasIndex('idx_user_status_created')) {
            $this->addSql('DROP INDEX idx_user_status_created ON cp_users');
        }
    }
}
