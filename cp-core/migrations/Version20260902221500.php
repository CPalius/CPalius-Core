<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CPalius Forum Engine 2.x alignment: node (last_post, node_type, link, capability),
 * thread (discussion_state, locked, first/last post), post (edit/attach),
 * prefix css_class.
 */
final class Version20260902221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds CPalius Forum Engine-aligned columns to forum nodes, threads, posts and prefixes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE forum_sections
            ADD last_post_id INT DEFAULT NULL,
            ADD last_poster_id INT DEFAULT NULL,
            ADD node_type VARCHAR(16) NOT NULL DEFAULT 'forum',
            ADD link_url VARCHAR(500) DEFAULT NULL,
            ADD required_capability VARCHAR(100) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_forum_section_node_type ON forum_sections (node_type)');
        $this->addSql('ALTER TABLE forum_sections ADD CONSTRAINT FK_FORUM_SECTION_LAST_POSTER FOREIGN KEY (last_poster_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql("UPDATE forum_sections SET node_type = 'category' WHERE section_type IN ('division', 'category')");
        $this->addSql("UPDATE forum_sections SET node_type = 'forum' WHERE section_type = 'subcategory'");

        $this->addSql("ALTER TABLE forum_topics
            ADD discussion_state VARCHAR(16) NOT NULL DEFAULT 'visible',
            ADD locked TINYINT(1) NOT NULL DEFAULT 0,
            ADD first_post_id INT DEFAULT NULL,
            ADD last_post_id INT DEFAULT NULL,
            ADD last_post_date DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_forum_topic_discussion_state ON forum_topics (discussion_state)');
        $this->addSql('CREATE INDEX idx_forum_topic_last_post_date ON forum_topics (last_post_date)');
        $this->addSql('UPDATE forum_topics SET locked = 1 WHERE state = 1');

        $this->addSql('UPDATE forum_topics t
            SET t.first_post_id = (SELECT MIN(p.id) FROM forum_posts p WHERE p.topic_id = t.id),
                t.last_post_id = (SELECT MAX(p.id) FROM forum_posts p WHERE p.topic_id = t.id),
                t.last_post_date = (SELECT MAX(p.created_at) FROM forum_posts p WHERE p.topic_id = t.id)');

        $this->addSql('UPDATE forum_sections s
            INNER JOIN (
                SELECT p.section_id, p.id AS post_id, p.author_id
                FROM forum_posts p
                INNER JOIN (
                    SELECT section_id, MAX(id) AS max_id FROM forum_posts GROUP BY section_id
                ) latest ON latest.max_id = p.id
            ) last ON last.section_id = s.id
            SET s.last_post_id = last.post_id, s.last_poster_id = last.author_id');

        $this->addSql('ALTER TABLE forum_posts
            ADD attach_count INT NOT NULL DEFAULT 0,
            ADD edit_count INT NOT NULL DEFAULT 0,
            ADD last_edit_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE forum_posts SET edit_count = 1, last_edit_date = updated_at WHERE updated_at IS NOT NULL');

        $this->addSql('ALTER TABLE forum_topic_prefixes ADD css_class VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE forum_topic_prefixes SET css_class = 'forum-prefix--announcement' WHERE label = 'Duyuru'");
        $this->addSql("UPDATE forum_topic_prefixes SET css_class = 'forum-prefix--question' WHERE label = 'Soru'");
        $this->addSql("UPDATE forum_topic_prefixes SET css_class = 'forum-prefix--solved' WHERE label = '�?özüldü'");

        $this->addSql("INSERT INTO forum_topic_prefixes (label, color, sort_order, css_class)
            SELECT 'Rehber', '#2980B9', 3, 'forum-prefix--guide'
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM forum_topic_prefixes WHERE label = 'Rehber')");

        $this->addSql("UPDATE forum_topics t
            INNER JOIN (
                SELECT slug FROM forum_topics WHERE slug IS NOT NULL AND slug != '' GROUP BY slug HAVING COUNT(*) > 1
            ) dup ON dup.slug = t.slug
            SET t.slug = CONCAT(t.slug, '-', t.id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_sections DROP FOREIGN KEY FK_FORUM_SECTION_LAST_POSTER');
        $this->addSql('DROP INDEX idx_forum_section_node_type ON forum_sections');
        $this->addSql('ALTER TABLE forum_sections DROP last_post_id, DROP last_poster_id, DROP node_type, DROP link_url, DROP required_capability');

        $this->addSql('DROP INDEX idx_forum_topic_discussion_state ON forum_topics');
        $this->addSql('DROP INDEX idx_forum_topic_last_post_date ON forum_topics');
        $this->addSql('ALTER TABLE forum_topics DROP discussion_state, DROP locked, DROP first_post_id, DROP last_post_id, DROP last_post_date');

        $this->addSql('ALTER TABLE forum_posts DROP attach_count, DROP edit_count, DROP last_edit_date');
        $this->addSql('ALTER TABLE forum_topic_prefixes DROP css_class');
    }
}
