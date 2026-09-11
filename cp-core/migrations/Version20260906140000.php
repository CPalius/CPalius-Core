<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unique logged-in readers per topic for the thread "who read this" strip.
 */
final class Version20260906140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates forum_topic_views (unique reader rows per topic + user).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forum_topic_views (
            id INT AUTO_INCREMENT NOT NULL,
            topic_id INT NOT NULL,
            user_id INT NOT NULL,
            last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_forum_topic_view_topic (topic_id),
            INDEX idx_forum_topic_view_user (user_id),
            UNIQUE INDEX uniq_forum_topic_view_topic_user (topic_id, user_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE forum_topic_views ADD CONSTRAINT FK_FTV_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_topic_views ADD CONSTRAINT FK_FTV_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_topic_views DROP FOREIGN KEY FK_FTV_TOPIC');
        $this->addSql('ALTER TABLE forum_topic_views DROP FOREIGN KEY FK_FTV_USER');
        $this->addSql('DROP TABLE forum_topic_views');
    }
}
