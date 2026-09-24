<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum module tables — CPalius adaptation of Cotonti cot_forum_* schema.
 * Default sections: pub (container) > general, offtopic.
 */
final class Version20260825200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates Forum module tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE forum_sections (
                id INT AUTO_INCREMENT NOT NULL,
                parent_id INT DEFAULT NULL,
                code VARCHAR(64) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_container TINYINT(1) NOT NULL DEFAULT 0,
                allow_topics TINYINT(1) NOT NULL DEFAULT 1,
                topic_count INT NOT NULL DEFAULT 0,
                post_count INT NOT NULL DEFAULT 0,
                view_count INT NOT NULL DEFAULT 0,
                last_topic_id INT DEFAULT NULL,
                last_topic_title VARCHAR(255) DEFAULT NULL,
                last_post_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_poster_name VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_forum_section_code (code),
                UNIQUE INDEX uniq_forum_section_slug_locale (slug, locale),
                INDEX idx_forum_section_locale (locale),
                INDEX IDX_FORUM_SECTION_PARENT (parent_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_topics (
                id INT AUTO_INCREMENT NOT NULL,
                section_id INT NOT NULL,
                moved_to_topic_id INT DEFAULT NULL,
                first_poster_id INT DEFAULT NULL,
                last_poster_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                mode SMALLINT NOT NULL DEFAULT 0,
                state SMALLINT NOT NULL DEFAULT 0,
                sticky TINYINT(1) NOT NULL DEFAULT 0,
                view_count INT NOT NULL DEFAULT 0,
                post_count INT NOT NULL DEFAULT 0,
                first_poster_name VARCHAR(100) NOT NULL,
                last_poster_name VARCHAR(100) DEFAULT NULL,
                preview VARCHAR(128) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_forum_topic_updated (updated_at),
                INDEX idx_forum_topic_state (state),
                INDEX idx_forum_topic_sticky (sticky),
                INDEX IDX_FORUM_TOPIC_SECTION (section_id),
                INDEX IDX_FORUM_TOPIC_MOVED (moved_to_topic_id),
                INDEX IDX_FORUM_TOPIC_FIRST_POSTER (first_poster_id),
                INDEX IDX_FORUM_TOPIC_LAST_POSTER (last_poster_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_posts (
                id INT AUTO_INCREMENT NOT NULL,
                topic_id INT NOT NULL,
                section_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                poster_name VARCHAR(100) NOT NULL,
                body LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by_name VARCHAR(100) DEFAULT NULL,
                poster_ip VARCHAR(64) DEFAULT NULL,
                INDEX idx_forum_post_created (created_at),
                INDEX idx_forum_post_topic (topic_id, id),
                INDEX IDX_FORUM_POST_SECTION (section_id),
                INDEX IDX_FORUM_POST_AUTHOR (author_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE forum_sections ADD CONSTRAINT FK_FORUM_SECTION_PARENT FOREIGN KEY (parent_id) REFERENCES forum_sections (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_MOVED FOREIGN KEY (moved_to_topic_id) REFERENCES forum_topics (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_FIRST_POSTER FOREIGN KEY (first_poster_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_LAST_POSTER FOREIGN KEY (last_poster_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POST_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POST_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POST_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_posts DROP FOREIGN KEY FK_FORUM_POST_AUTHOR');
        $this->addSql('ALTER TABLE forum_posts DROP FOREIGN KEY FK_FORUM_POST_SECTION');
        $this->addSql('ALTER TABLE forum_posts DROP FOREIGN KEY FK_FORUM_POST_TOPIC');
        $this->addSql('ALTER TABLE forum_topics DROP FOREIGN KEY FK_FORUM_TOPIC_LAST_POSTER');
        $this->addSql('ALTER TABLE forum_topics DROP FOREIGN KEY FK_FORUM_TOPIC_FIRST_POSTER');
        $this->addSql('ALTER TABLE forum_topics DROP FOREIGN KEY FK_FORUM_TOPIC_MOVED');
        $this->addSql('ALTER TABLE forum_topics DROP FOREIGN KEY FK_FORUM_TOPIC_SECTION');
        $this->addSql('ALTER TABLE forum_sections DROP FOREIGN KEY FK_FORUM_SECTION_PARENT');
        $this->addSql('DROP TABLE forum_posts');
        $this->addSql('DROP TABLE forum_topics');
        $this->addSql('DROP TABLE forum_sections');
    }
}
