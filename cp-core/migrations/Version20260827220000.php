<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum notifications and user reputation tables (MegaforBB-style).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forum_notifications (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            sender_user_id INT DEFAULT NULL,
            sender_name VARCHAR(180) DEFAULT NULL,
            type VARCHAR(32) NOT NULL,
            content_type VARCHAR(32) NOT NULL,
            content_id INT DEFAULT NULL,
            data JSON NOT NULL,
            read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_forum_notif_user_unread (user_id, read_at),
            INDEX idx_forum_notif_content (content_type, content_id),
            INDEX idx_forum_notif_created (created_at),
            INDEX IDX_FN_USER (user_id),
            INDEX IDX_FN_SENDER (sender_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE forum_user_reputations (
            id INT AUTO_INCREMENT NOT NULL,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            topic_id INT DEFAULT NULL,
            post_id INT DEFAULT NULL,
            value SMALLINT NOT NULL,
            reason VARCHAR(32) NOT NULL,
            comment VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_forum_rep_to (to_user_id, created_at),
            INDEX idx_forum_rep_from (from_user_id),
            INDEX idx_forum_rep_topic (topic_id),
            INDEX IDX_FUR_POST (post_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE forum_notifications ADD CONSTRAINT FK_FN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_notifications ADD CONSTRAINT FK_FN_SENDER FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_user_reputations ADD CONSTRAINT FK_FUR_FROM FOREIGN KEY (from_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_user_reputations ADD CONSTRAINT FK_FUR_TO FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_user_reputations ADD CONSTRAINT FK_FUR_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_user_reputations ADD CONSTRAINT FK_FUR_POST FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_notifications DROP FOREIGN KEY FK_FN_USER');
        $this->addSql('ALTER TABLE forum_notifications DROP FOREIGN KEY FK_FN_SENDER');
        $this->addSql('ALTER TABLE forum_user_reputations DROP FOREIGN KEY FK_FUR_FROM');
        $this->addSql('ALTER TABLE forum_user_reputations DROP FOREIGN KEY FK_FUR_TO');
        $this->addSql('ALTER TABLE forum_user_reputations DROP FOREIGN KEY FK_FUR_TOPIC');
        $this->addSql('ALTER TABLE forum_user_reputations DROP FOREIGN KEY FK_FUR_POST');
        $this->addSql('DROP TABLE forum_notifications');
        $this->addSql('DROP TABLE forum_user_reputations');
    }
}
