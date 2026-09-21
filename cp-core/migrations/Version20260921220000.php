<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum Phase A: parent_path, visibility buckets, delete metadata, and
 * incremental-counter tables.
 *
 * topic_count / post_count keep their existing values and now officially mean
 * visible totals. New held/deleted columns start at 0; Recount will fill them.
 * parent_path is backfilled from the adjacency list (self-inclusive /1/5/12/).
 *
 * Guarded on information_schema so a site that already ran the module SQL file
 * (or a partial patch) does not fail on a second ADD COLUMN.
 */
final class Version20260921220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum Phase A: parent_path, count buckets, delete metadata, user/board stats, view buffer.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->alterSections();
        $this->alterTopics();
        $this->alterPosts();
        $this->dedupeAndUniqueReadMarkers();
        $this->createUserStats();
        $this->createBoardStats();
        $this->createViewBuffer();
        $this->backfillParentPath();
        $this->backfillDeleteMetadata();
        $this->backfillTopicFlags();
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('cp_forum_topic_view_buffer')) {
            $this->connection->executeStatement('DROP TABLE cp_forum_topic_view_buffer');
        }
        if ($this->tableExists('cp_forum_user_stats')) {
            $this->connection->executeStatement('DROP TABLE cp_forum_user_stats');
        }
        if ($this->tableExists('cp_forum_board_stats')) {
            $this->connection->executeStatement('DROP TABLE cp_forum_board_stats');
        }

        $this->dropIndexIfExists('cp_forum_read_markers', 'uniq_forum_read_user_section');

        if ($this->tableExists('cp_forum_posts')) {
            $this->dropForeignKeyIfExists('cp_forum_posts', 'FK_FORUM_POST_DELETED_BY');
            $this->dropIndexIfExists('cp_forum_posts', 'idx_forum_post_deleted_by');
            foreach (['deleted_at', 'deleted_by_id', 'delete_reason'] as $column) {
                $this->dropColumnIfExists('cp_forum_posts', $column);
            }
        }

        if ($this->tableExists('cp_forum_topics')) {
            $this->dropForeignKeyIfExists('cp_forum_topics', 'FK_FORUM_TOPIC_DELETED_BY');
            $this->dropIndexIfExists('cp_forum_topics', 'idx_forum_topic_section_list');
            $this->dropIndexIfExists('cp_forum_topics', 'idx_forum_topic_deleted_by');
            foreach ([
                'post_count_held', 'post_count_deleted',
                'deleted_at', 'deleted_by_id', 'delete_reason',
                'has_attachment', 'is_reported',
            ] as $column) {
                $this->dropColumnIfExists('cp_forum_topics', $column);
            }
        }

        if ($this->tableExists('cp_forum_sections')) {
            $this->dropIndexIfExists('cp_forum_sections', 'idx_forum_section_parent_path');
            foreach ([
                'parent_path',
                'topic_count_held', 'topic_count_deleted',
                'post_count_held', 'post_count_deleted',
            ] as $column) {
                $this->dropColumnIfExists('cp_forum_sections', $column);
            }
        }
    }

    private function alterSections(): void
    {
        if (!$this->tableExists('cp_forum_sections')) {
            return;
        }

        if (!$this->columnExists('cp_forum_sections', 'parent_path')) {
            $this->connection->executeStatement(
                "ALTER TABLE cp_forum_sections
                 ADD COLUMN parent_path VARCHAR(255) NOT NULL DEFAULT ''",
            );
        }
        if (!$this->columnExists('cp_forum_sections', 'topic_count_held')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_sections
                 ADD COLUMN topic_count_held INT NOT NULL DEFAULT 0,
                 ADD COLUMN topic_count_deleted INT NOT NULL DEFAULT 0,
                 ADD COLUMN post_count_held INT NOT NULL DEFAULT 0,
                 ADD COLUMN post_count_deleted INT NOT NULL DEFAULT 0',
            );
        }
        $this->addIndexIfMissing(
            'cp_forum_sections',
            'idx_forum_section_parent_path',
            'ALTER TABLE cp_forum_sections ADD INDEX idx_forum_section_parent_path (parent_path)',
        );
    }

    private function alterTopics(): void
    {
        if (!$this->tableExists('cp_forum_topics')) {
            return;
        }

        if (!$this->columnExists('cp_forum_topics', 'post_count_held')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_topics
                 ADD COLUMN post_count_held INT NOT NULL DEFAULT 0,
                 ADD COLUMN post_count_deleted INT NOT NULL DEFAULT 0',
            );
        }
        if (!$this->columnExists('cp_forum_topics', 'deleted_at')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_topics
                 ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                 ADD COLUMN deleted_by_id INT DEFAULT NULL,
                 ADD COLUMN delete_reason VARCHAR(255) DEFAULT NULL',
            );
        }
        if (!$this->columnExists('cp_forum_topics', 'has_attachment')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_topics
                 ADD COLUMN has_attachment TINYINT(1) NOT NULL DEFAULT 0,
                 ADD COLUMN is_reported TINYINT(1) NOT NULL DEFAULT 0',
            );
        }

        $this->addIndexIfMissing(
            'cp_forum_topics',
            'idx_forum_topic_section_list',
            'ALTER TABLE cp_forum_topics ADD INDEX idx_forum_topic_section_list (section_id, discussion_state, sticky, last_post_date)',
        );
        $this->addIndexIfMissing(
            'cp_forum_topics',
            'idx_forum_topic_deleted_by',
            'ALTER TABLE cp_forum_topics ADD INDEX idx_forum_topic_deleted_by (deleted_by_id)',
        );
        $this->addForeignKeyIfMissing(
            'cp_forum_topics',
            'FK_FORUM_TOPIC_DELETED_BY',
            'ALTER TABLE cp_forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
        );
    }

    private function alterPosts(): void
    {
        if (!$this->tableExists('cp_forum_posts')) {
            return;
        }

        if (!$this->columnExists('cp_forum_posts', 'deleted_at')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_posts
                 ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                 ADD COLUMN deleted_by_id INT DEFAULT NULL,
                 ADD COLUMN delete_reason VARCHAR(255) DEFAULT NULL',
            );
        }

        $this->addIndexIfMissing(
            'cp_forum_posts',
            'idx_forum_post_deleted_by',
            'ALTER TABLE cp_forum_posts ADD INDEX idx_forum_post_deleted_by (deleted_by_id)',
        );
        $this->addForeignKeyIfMissing(
            'cp_forum_posts',
            'FK_FORUM_POST_DELETED_BY',
            'ALTER TABLE cp_forum_posts ADD CONSTRAINT FK_FORUM_POST_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
        );
    }

    private function dedupeAndUniqueReadMarkers(): void
    {
        if (!$this->tableExists('cp_forum_read_markers')) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE m1 FROM cp_forum_read_markers m1
             INNER JOIN cp_forum_read_markers m2
                ON m1.user_id = m2.user_id
               AND m1.section_id <=> m2.section_id
               AND m1.id < m2.id',
        );

        $this->addIndexIfMissing(
            'cp_forum_read_markers',
            'uniq_forum_read_user_section',
            'ALTER TABLE cp_forum_read_markers ADD UNIQUE INDEX uniq_forum_read_user_section (user_id, section_id)',
        );
    }

    private function createUserStats(): void
    {
        if ($this->tableExists('cp_forum_user_stats')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_user_stats (
                user_id INT NOT NULL,
                post_count INT NOT NULL DEFAULT 0,
                topic_count INT NOT NULL DEFAULT 0,
                like_received INT NOT NULL DEFAULT 0,
                last_posted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_user_stats_posts (post_count),
                PRIMARY KEY(user_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_user_stats
             ADD CONSTRAINT FK_FORUM_USER_STATS_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE',
        );
    }

    private function createBoardStats(): void
    {
        if ($this->tableExists('cp_forum_board_stats')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_board_stats (
                locale VARCHAR(5) NOT NULL,
                topic_count INT NOT NULL DEFAULT 0,
                post_count INT NOT NULL DEFAULT 0,
                topic_count_held INT NOT NULL DEFAULT 0,
                post_count_held INT NOT NULL DEFAULT 0,
                last_topic_id INT DEFAULT NULL,
                last_post_id INT DEFAULT NULL,
                last_post_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                last_poster_id INT DEFAULT NULL,
                last_poster_name VARCHAR(100) DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_FORUM_BOARD_STATS_POSTER (last_poster_id),
                PRIMARY KEY(locale)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_board_stats
             ADD CONSTRAINT FK_FORUM_BOARD_STATS_POSTER FOREIGN KEY (last_poster_id) REFERENCES cp_users (id) ON DELETE SET NULL',
        );
    }

    private function createViewBuffer(): void
    {
        if ($this->tableExists('cp_forum_topic_view_buffer')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_topic_view_buffer (
                id INT AUTO_INCREMENT NOT NULL,
                topic_id INT NOT NULL,
                seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_view_buf_topic (topic_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_topic_view_buffer
             ADD CONSTRAINT FK_FORUM_VIEW_BUF_TOPIC FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE',
        );
    }

    /**
     * Roots become /id/. Children inherit the parent's path, then append /id/.
     * Sixteen passes cover any accidental deeper tree; CPalius is three levels.
     */
    private function backfillParentPath(): void
    {
        if (!$this->tableExists('cp_forum_sections') || !$this->columnExists('cp_forum_sections', 'parent_path')) {
            return;
        }

        $this->connection->executeStatement(
            "UPDATE cp_forum_sections
             SET parent_path = CONCAT('/', id, '/')
             WHERE parent_id IS NULL AND (parent_path = '' OR parent_path IS NULL)",
        );

        for ($i = 0; $i < 16; ++$i) {
            $affected = $this->connection->executeStatement(
                "UPDATE cp_forum_sections c
                 INNER JOIN cp_forum_sections p ON c.parent_id = p.id
                 SET c.parent_path = CONCAT(p.parent_path, c.id, '/')
                 WHERE c.parent_path = '' AND p.parent_path <> ''",
            );
            if ($affected === 0) {
                break;
            }
        }
    }

    private function backfillDeleteMetadata(): void
    {
        if ($this->tableExists('cp_forum_topics') && $this->columnExists('cp_forum_topics', 'deleted_at')) {
            $this->connection->executeStatement(
                "UPDATE cp_forum_topics
                 SET deleted_at = updated_at
                 WHERE discussion_state = 'deleted' AND deleted_at IS NULL",
            );
        }

        if ($this->tableExists('cp_forum_posts') && $this->columnExists('cp_forum_posts', 'deleted_at')) {
            $this->connection->executeStatement(
                "UPDATE cp_forum_posts
                 SET deleted_at = COALESCE(updated_at, created_at)
                 WHERE discussion_state = 'deleted' AND deleted_at IS NULL",
            );
        }
    }

    private function backfillTopicFlags(): void
    {
        if (!$this->tableExists('cp_forum_topics') || !$this->columnExists('cp_forum_topics', 'has_attachment')) {
            return;
        }

        if ($this->tableExists('cp_forum_post_attachments') && $this->tableExists('cp_forum_posts')) {
            $this->connection->executeStatement(
                'UPDATE cp_forum_topics t
                 SET has_attachment = 1
                 WHERE EXISTS (
                    SELECT 1 FROM cp_forum_post_attachments a
                    INNER JOIN cp_forum_posts p ON p.id = a.post_id
                    WHERE p.topic_id = t.id
                 )',
            );
        }

        if ($this->tableExists('cp_forum_post_reports') && $this->tableExists('cp_forum_posts')) {
            $this->connection->executeStatement(
                'UPDATE cp_forum_topics t
                 SET is_reported = 1
                 WHERE EXISTS (
                    SELECT 1 FROM cp_forum_post_reports r
                    INNER JOIN cp_forum_posts p ON p.id = r.post_id
                    WHERE p.topic_id = t.id AND r.status = 0
                 )',
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

    private function foreignKeyExists(string $table, string $name): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = \'FOREIGN KEY\'
               AND CONSTRAINT_NAME = ?',
            [$table, $name],
        ) > 0;
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        if (!$this->indexExists($table, $index)) {
            $this->connection->executeStatement($sql);
        }
    }

    private function addForeignKeyIfMissing(string $table, string $name, string $sql): void
    {
        if (!$this->tableExists('cp_users') || $this->foreignKeyExists($table, $name)) {
            return;
        }

        $this->connection->executeStatement($sql);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            $this->connection->executeStatement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
        }
    }

    private function dropForeignKeyIfExists(string $table, string $name): void
    {
        if ($this->foreignKeyExists($table, $name)) {
            $this->connection->executeStatement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if ($this->columnExists($table, $column)) {
            $this->connection->executeStatement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
        }
    }
}
