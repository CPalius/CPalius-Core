<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum Phase C: participation index, announcements, warnings, ban filters,
 * ignore list, and P1 columns on section/post/poll/attachment/user_stats.
 *
 * Guarded so the module SQL file and this class can each arrive first.
 */
final class Version20260922020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum Phase C: topics_posted, announcements, warnings, ban filters, user blocks, P1 columns.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->alterUserStats();
        $this->alterSections();
        $this->alterPosts();
        $this->alterAttachments();
        $this->alterPolls();
        $this->createTopicsPosted();
        $this->createAnnouncements();
        $this->createWarnings();
        $this->createBanFilters();
        $this->createUserBlocks();
        $this->backfillTopicsPosted();
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'cp_forum_topics_posted',
            'cp_forum_announcements',
            'cp_forum_warnings',
            'cp_forum_ban_filters',
            'cp_forum_user_blocks',
        ] as $table) {
            if ($this->tableExists($table)) {
                $this->connection->executeStatement('DROP TABLE `'.$table.'`');
            }
        }

        if ($this->tableExists('cp_forum_posts')) {
            $this->dropForeignKeyIfExists('cp_forum_posts', 'FK_FORUM_POST_EDITED_BY');
            $this->dropIndexIfExists('cp_forum_posts', 'idx_forum_post_edited_by');
            foreach (['edit_reason', 'edit_locked', 'edited_by_id'] as $column) {
                $this->dropColumnIfExists('cp_forum_posts', $column);
            }
        }

        $this->dropColumnIfExists('cp_forum_user_stats', 'warning_points');
        foreach (['rules_html', 'access_secret_hash'] as $column) {
            $this->dropColumnIfExists('cp_forum_sections', $column);
        }
        $this->dropColumnIfExists('cp_forum_post_attachments', 'download_count');
        foreach (['is_closed', 'is_public', 'allow_change'] as $column) {
            $this->dropColumnIfExists('cp_forum_polls', $column);
        }
    }

    private function alterUserStats(): void
    {
        if (!$this->tableExists('cp_forum_user_stats') || $this->columnExists('cp_forum_user_stats', 'warning_points')) {
            return;
        }

        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_user_stats ADD COLUMN warning_points INT NOT NULL DEFAULT 0',
        );
    }

    private function alterSections(): void
    {
        if (!$this->tableExists('cp_forum_sections')) {
            return;
        }

        if (!$this->columnExists('cp_forum_sections', 'rules_html')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_sections ADD COLUMN rules_html LONGTEXT DEFAULT NULL',
            );
        }
        if (!$this->columnExists('cp_forum_sections', 'access_secret_hash')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_sections ADD COLUMN access_secret_hash VARCHAR(255) DEFAULT NULL',
            );
        }
    }

    private function alterPosts(): void
    {
        if (!$this->tableExists('cp_forum_posts')) {
            return;
        }

        if (!$this->columnExists('cp_forum_posts', 'edit_reason')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_posts ADD COLUMN edit_reason VARCHAR(255) DEFAULT NULL',
            );
        }
        if (!$this->columnExists('cp_forum_posts', 'edit_locked')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_posts ADD COLUMN edit_locked TINYINT(1) NOT NULL DEFAULT 0',
            );
        }
        if (!$this->columnExists('cp_forum_posts', 'edited_by_id')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_posts ADD COLUMN edited_by_id INT DEFAULT NULL',
            );
        }
        $this->addIndexIfMissing(
            'cp_forum_posts',
            'idx_forum_post_edited_by',
            'ALTER TABLE cp_forum_posts ADD INDEX idx_forum_post_edited_by (edited_by_id)',
        );
        $this->addForeignKeyIfMissing(
            'cp_forum_posts',
            'FK_FORUM_POST_EDITED_BY',
            'ALTER TABLE cp_forum_posts ADD CONSTRAINT FK_FORUM_POST_EDITED_BY FOREIGN KEY (edited_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
        );
    }

    private function alterAttachments(): void
    {
        if (!$this->tableExists('cp_forum_post_attachments') || $this->columnExists('cp_forum_post_attachments', 'download_count')) {
            return;
        }

        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_post_attachments ADD COLUMN download_count INT NOT NULL DEFAULT 0',
        );
    }

    private function alterPolls(): void
    {
        if (!$this->tableExists('cp_forum_polls')) {
            return;
        }

        if (!$this->columnExists('cp_forum_polls', 'is_closed')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_polls ADD COLUMN is_closed TINYINT(1) NOT NULL DEFAULT 0',
            );
        }
        if (!$this->columnExists('cp_forum_polls', 'is_public')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_polls ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0',
            );
        }
        if (!$this->columnExists('cp_forum_polls', 'allow_change')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_polls ADD COLUMN allow_change TINYINT(1) NOT NULL DEFAULT 0',
            );
        }
    }

    private function createTopicsPosted(): void
    {
        if ($this->tableExists('cp_forum_topics_posted')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_topics_posted (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                topic_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_topics_posted (user_id, topic_id),
                INDEX idx_forum_topics_posted_user (user_id),
                INDEX idx_forum_topics_posted_topic (topic_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_FORUM_POSTED_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
                CONSTRAINT FK_FORUM_POSTED_TOPIC FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    private function createAnnouncements(): void
    {
        if ($this->tableExists('cp_forum_announcements')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_announcements (
                id INT AUTO_INCREMENT NOT NULL,
                section_id INT DEFAULT NULL,
                body LONGTEXT NOT NULL,
                starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_announcement_section (section_id),
                INDEX idx_forum_announcement_window (is_active, starts_at, ends_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_FORUM_ANN_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    private function createWarnings(): void
    {
        if ($this->tableExists('cp_forum_warnings')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_warnings (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                warned_by_id INT DEFAULT NULL,
                points INT NOT NULL DEFAULT 1,
                reason LONGTEXT NOT NULL,
                expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_warning_user (user_id),
                INDEX idx_forum_warning_by (warned_by_id),
                INDEX idx_forum_warning_expires (expires_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_FORUM_WARN_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
                CONSTRAINT FK_FORUM_WARN_BY FOREIGN KEY (warned_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    private function createBanFilters(): void
    {
        if ($this->tableExists('cp_forum_ban_filters')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_ban_filters (
                id INT AUTO_INCREMENT NOT NULL,
                type VARCHAR(8) NOT NULL,
                rule VARCHAR(255) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_ban_filter_slot (type, rule),
                INDEX idx_forum_ban_filter_type (type),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    private function createUserBlocks(): void
    {
        if ($this->tableExists('cp_forum_user_blocks')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_user_blocks (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                blocked_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_user_block (user_id, blocked_id),
                INDEX idx_forum_user_block_user (user_id),
                INDEX idx_forum_user_block_blocked (blocked_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_FORUM_BLOCK_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
                CONSTRAINT FK_FORUM_BLOCK_BLOCKED FOREIGN KEY (blocked_id) REFERENCES cp_users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    private function backfillTopicsPosted(): void
    {
        if (!$this->tableExists('cp_forum_topics_posted') || !$this->tableExists('cp_forum_posts')) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT IGNORE INTO cp_forum_topics_posted (user_id, topic_id, created_at)
             SELECT p.author_id, p.topic_id, MIN(p.created_at)
             FROM cp_forum_posts p
             WHERE p.author_id IS NOT NULL
             GROUP BY p.author_id, p.topic_id',
        );
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
        if ($this->tableExists($table) && $this->columnExists($table, $column)) {
            $this->connection->executeStatement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
        }
    }
}
