<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum topics share a translation group so AI (and Studio) can attach a
 * sibling locale instead of opening a duplicate thread.
 *
 * Guarded: the same ALTER also lives in
 * cp-content/modules/Forum/Resources/migrations/20260922_forum_topic_translation_group.sql.
 * Either file may arrive first on an upgrade.
 */
final class Version20260922140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum topics: translation_group_id for locale siblings.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('cp_forum_topics')) {
            return;
        }

        if (!$this->columnExists('cp_forum_topics', 'translation_group_id')) {
            $this->connection->executeStatement(
                "ALTER TABLE cp_forum_topics ADD COLUMN translation_group_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'",
            );
        }

        if (!$this->indexExists('cp_forum_topics', 'uniq_forum_topic_translation_group_locale')) {
            $this->connection->executeStatement(
                'CREATE UNIQUE INDEX uniq_forum_topic_translation_group_locale ON cp_forum_topics (translation_group_id, locale)',
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('cp_forum_topics')) {
            return;
        }

        if ($this->indexExists('cp_forum_topics', 'uniq_forum_topic_translation_group_locale')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_topics DROP INDEX uniq_forum_topic_translation_group_locale',
            );
        }

        if ($this->columnExists('cp_forum_topics', 'translation_group_id')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_topics DROP COLUMN translation_group_id',
            );
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        ) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        ) > 0;
    }
}
