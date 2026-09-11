<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GC2: copy leftover forum_notifications into cp_notifications, then drop the orphan table.
 */
final class Version20260912150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate leftover forum_notifications into cp_notifications and drop the table.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['forum_notifications'])) {
            return;
        }

        if ($sm->tablesExist(['cp_notifications'])) {
            $this->addSql(
                "INSERT INTO cp_notifications (
                    user_id, actor_user_id, event_key, actor_name, subject_type, subject_id,
                    data, dedupe_key, read_at, created_at, tenant_id
                )
                SELECT
                    f.user_id,
                    f.sender_user_id,
                    CONCAT('forum.', f.type),
                    f.sender_name,
                    f.content_type,
                    f.content_id,
                    f.data,
                    NULL,
                    f.read_at,
                    f.created_at,
                    NULL
                FROM forum_notifications f
                WHERE NOT EXISTS (
                    SELECT 1 FROM cp_notifications c
                    WHERE c.user_id = f.user_id
                      AND c.event_key = CONCAT('forum.', f.type)
                      AND c.created_at = f.created_at
                      AND (c.subject_id <=> f.content_id)
                )"
            );
        }

        $this->addSql('DROP TABLE forum_notifications');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['forum_notifications'])) {
            return;
        }

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
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE forum_notifications ADD CONSTRAINT FK_FN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_notifications ADD CONSTRAINT FK_FN_SENDER FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }
}
