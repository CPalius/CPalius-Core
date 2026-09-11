<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum engine: attachments, polls, watches, drafts, unread markers,
 * moderation log, censor words, post approval, IP/email bans.
 */
final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum attachments, polls, watches, drafts, unread, mod log, censor, post approval, IP bans.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if (!$this->columnExists($sm, 'forum_posts', 'discussion_state')) {
            $this->addSql("ALTER TABLE forum_posts ADD discussion_state VARCHAR(16) NOT NULL DEFAULT 'visible'");
            $this->addSql('CREATE INDEX idx_forum_post_discussion_state ON forum_posts (discussion_state)');
        }

        if ($this->columnExists($sm, 'forum_bans', 'user_id')) {
            $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_FORUM_BAN_USER');
            $this->addSql('ALTER TABLE forum_bans MODIFY user_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_FORUM_BAN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        if (!$this->columnExists($sm, 'forum_bans', 'ip_address')) {
            $this->addSql('ALTER TABLE forum_bans ADD ip_address VARCHAR(64) DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_forum_ban_ip ON forum_bans (ip_address)');
        }

        if (!$this->columnExists($sm, 'forum_bans', 'email')) {
            $this->addSql('ALTER TABLE forum_bans ADD email VARCHAR(180) DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_forum_ban_email ON forum_bans (email)');
        }

        if (!$sm->tablesExist(['forum_post_attachments'])) {
            $this->addSql('CREATE TABLE forum_post_attachments (
                id INT AUTO_INCREMENT NOT NULL,
                post_id INT NOT NULL,
                asset_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_attach_post (post_id),
                INDEX idx_forum_attach_asset (asset_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_post_attachments ADD CONSTRAINT FK_FORUM_ATTACH_POST FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_post_attachments ADD CONSTRAINT FK_FORUM_ATTACH_ASSET FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_polls'])) {
            $this->addSql('CREATE TABLE forum_polls (
                id INT AUTO_INCREMENT NOT NULL,
                topic_id INT NOT NULL,
                question VARCHAR(255) NOT NULL,
                max_choices SMALLINT NOT NULL,
                hide_until_close TINYINT(1) NOT NULL,
                closes_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_poll_topic (topic_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_polls ADD CONSTRAINT FK_FORUM_POLL_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_poll_options'])) {
            $this->addSql('CREATE TABLE forum_poll_options (
                id INT AUTO_INCREMENT NOT NULL,
                poll_id INT NOT NULL,
                label VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL,
                vote_count INT NOT NULL,
                INDEX idx_forum_poll_opt_poll (poll_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_poll_options ADD CONSTRAINT FK_FORUM_POLL_OPT_POLL FOREIGN KEY (poll_id) REFERENCES forum_polls (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_poll_votes'])) {
            $this->addSql('CREATE TABLE forum_poll_votes (
                id INT AUTO_INCREMENT NOT NULL,
                poll_id INT NOT NULL,
                option_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_poll_vote (poll_id, user_id, option_id),
                INDEX idx_forum_poll_vote_user (user_id),
                INDEX idx_forum_poll_vote_option (option_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_poll_votes ADD CONSTRAINT FK_FORUM_POLL_VOTE_POLL FOREIGN KEY (poll_id) REFERENCES forum_polls (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_poll_votes ADD CONSTRAINT FK_FORUM_POLL_VOTE_OPTION FOREIGN KEY (option_id) REFERENCES forum_poll_options (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_poll_votes ADD CONSTRAINT FK_FORUM_POLL_VOTE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_topic_watches'])) {
            $this->addSql('CREATE TABLE forum_topic_watches (
                id INT AUTO_INCREMENT NOT NULL,
                topic_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_topic_watch (topic_id, user_id),
                INDEX idx_forum_watch_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_topic_watches ADD CONSTRAINT FK_FORUM_WATCH_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_topic_watches ADD CONSTRAINT FK_FORUM_WATCH_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_drafts'])) {
            $this->addSql('CREATE TABLE forum_drafts (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                topic_id INT DEFAULT NULL,
                section_id INT DEFAULT NULL,
                title VARCHAR(255) DEFAULT NULL,
                body LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_draft_user (user_id),
                INDEX idx_forum_draft_topic (topic_id),
                INDEX idx_forum_draft_section (section_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_drafts ADD CONSTRAINT FK_FORUM_DRAFT_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_drafts ADD CONSTRAINT FK_FORUM_DRAFT_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_drafts ADD CONSTRAINT FK_FORUM_DRAFT_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_read_markers'])) {
            $this->addSql('CREATE TABLE forum_read_markers (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                section_id INT DEFAULT NULL,
                marked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_read_marker (user_id, section_id),
                INDEX idx_forum_read_section (section_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_read_markers ADD CONSTRAINT FK_FORUM_READ_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_read_markers ADD CONSTRAINT FK_FORUM_READ_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
        }

        if (!$sm->tablesExist(['forum_moderation_logs'])) {
            $this->addSql('CREATE TABLE forum_moderation_logs (
                id INT AUTO_INCREMENT NOT NULL,
                actor_id INT DEFAULT NULL,
                action VARCHAR(32) NOT NULL,
                target_type VARCHAR(16) NOT NULL,
                target_id INT NOT NULL,
                details JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_forum_modlog_created (created_at),
                INDEX idx_forum_modlog_target (target_type, target_id),
                INDEX idx_forum_modlog_actor (actor_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_moderation_logs ADD CONSTRAINT FK_FORUM_MODLOG_ACTOR FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$sm->tablesExist(['forum_censor_words'])) {
            $this->addSql('CREATE TABLE forum_censor_words (
                id INT AUTO_INCREMENT NOT NULL,
                word VARCHAR(100) NOT NULL,
                replacement VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_censor_word (word),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        foreach ([
            'forum_censor_words',
            'forum_moderation_logs',
            'forum_read_markers',
            'forum_drafts',
            'forum_topic_watches',
            'forum_poll_votes',
            'forum_poll_options',
            'forum_polls',
            'forum_post_attachments',
        ] as $table) {
            if ($sm->tablesExist([$table])) {
                $this->addSql('DROP TABLE '.$table);
            }
        }

        if ($this->columnExists($sm, 'forum_posts', 'discussion_state')) {
            $this->addSql('ALTER TABLE forum_posts DROP INDEX idx_forum_post_discussion_state');
            $this->addSql('ALTER TABLE forum_posts DROP discussion_state');
        }

        if ($this->columnExists($sm, 'forum_bans', 'ip_address')) {
            $this->addSql('ALTER TABLE forum_bans DROP INDEX idx_forum_ban_ip');
            $this->addSql('ALTER TABLE forum_bans DROP ip_address');
        }

        if ($this->columnExists($sm, 'forum_bans', 'email')) {
            $this->addSql('ALTER TABLE forum_bans DROP INDEX idx_forum_ban_email');
            $this->addSql('ALTER TABLE forum_bans DROP email');
        }
    }

    private function columnExists(\Doctrine\DBAL\Schema\AbstractSchemaManager $sm, string $table, string $column): bool
    {
        if (!$sm->tablesExist([$table])) {
            return false;
        }

        $details = $sm->introspectTable($table);

        return $details->hasColumn($column);
    }
}
