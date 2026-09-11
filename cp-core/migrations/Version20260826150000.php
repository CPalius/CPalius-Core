<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum mid-tier expansion: topic prefixes, user ranks, forum bans, and post likes.
 */
final class Version20260826150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds forum topic prefixes, user ranks, bans, and like tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE forum_topic_prefixes (
                id INT AUTO_INCREMENT NOT NULL,
                label VARCHAR(32) NOT NULL,
                color VARCHAR(7) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_user_ranks (
                id INT AUTO_INCREMENT NOT NULL,
                label VARCHAR(64) NOT NULL,
                color VARCHAR(7) NOT NULL,
                icon VARCHAR(64) DEFAULT NULL,
                min_posts INT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_bans (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                type SMALLINT NOT NULL DEFAULT 1,
                reason LONGTEXT NOT NULL,
                created_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_forum_ban_user (user_id),
                INDEX IDX_FORUM_BAN_CREATED_BY (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_post_likes (
                id INT AUTO_INCREMENT NOT NULL,
                post_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_forum_post_like (post_id, user_id),
                INDEX IDX_FORUM_POST_LIKE_USER (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE forum_topics ADD prefix_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_PREFIX FOREIGN KEY (prefix_id) REFERENCES forum_topic_prefixes (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FORUM_TOPIC_PREFIX ON forum_topics (prefix_id)');

        $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_FORUM_BAN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_FORUM_BAN_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_post_likes ADD CONSTRAINT FK_FORUM_POST_LIKE_POST FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_post_likes ADD CONSTRAINT FK_FORUM_POST_LIKE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // Default ranks — assigned automatically by post count.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->addSql("INSERT INTO forum_user_ranks (label, color, icon, min_posts, sort_order) VALUES ('Yeni Üye', '#8B9DAF', 'bi-person', 0, 0)");
        $this->addSql("INSERT INTO forum_user_ranks (label, color, icon, min_posts, sort_order) VALUES ('Üye', '#4A7C9B', 'bi-person-check', 10, 1)");
        $this->addSql("INSERT INTO forum_user_ranks (label, color, icon, min_posts, sort_order) VALUES ('Kıdemli Üye', '#4AADE4', 'bi-star', 50, 2)");
        $this->addSql("INSERT INTO forum_user_ranks (label, color, icon, min_posts, sort_order) VALUES ('Uzman', '#C8A86E', 'bi-award', 200, 3)");
        $this->addSql("INSERT INTO forum_user_ranks (label, color, icon, min_posts, sort_order) VALUES ('Moderatör', '#27AE60', 'bi-shield-check', NULL, 10)");

        // Default topic prefixes.
        $this->addSql("INSERT INTO forum_topic_prefixes (label, color, sort_order) VALUES ('Duyuru', '#C0392B', 0)");
        $this->addSql("INSERT INTO forum_topic_prefixes (label, color, sort_order) VALUES ('Soru', '#4AADE4', 1)");
        $this->addSql("INSERT INTO forum_topic_prefixes (label, color, sort_order) VALUES ('Çözüldü', '#27AE60', 2)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_topics DROP FOREIGN KEY FK_FORUM_TOPIC_PREFIX');
        $this->addSql('DROP INDEX IDX_FORUM_TOPIC_PREFIX ON forum_topics');
        $this->addSql('ALTER TABLE forum_topics DROP prefix_id');
        $this->addSql('ALTER TABLE forum_post_likes DROP FOREIGN KEY FK_FORUM_POST_LIKE_USER');
        $this->addSql('ALTER TABLE forum_post_likes DROP FOREIGN KEY FK_FORUM_POST_LIKE_POST');
        $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_FORUM_BAN_CREATED_BY');
        $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_FORUM_BAN_USER');
        $this->addSql('DROP TABLE forum_post_likes');
        $this->addSql('DROP TABLE forum_bans');
        $this->addSql('DROP TABLE forum_user_ranks');
        $this->addSql('DROP TABLE forum_topic_prefixes');
    }
}
