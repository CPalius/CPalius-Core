<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Core notification inbox + digest queue for T3.1.
 */
final class Version20260912130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_notifications and cp_notification_digest_queue.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if (!$sm->tablesExist(['cp_notifications'])) {
            $this->addSql('CREATE TABLE cp_notifications (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                actor_user_id INT DEFAULT NULL,
                event_key VARCHAR(128) NOT NULL,
                actor_name VARCHAR(180) DEFAULT NULL,
                subject_type VARCHAR(32) DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                data JSON NOT NULL,
                dedupe_key VARCHAR(191) DEFAULT NULL,
                read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                tenant_id VARCHAR(64) DEFAULT NULL,
                INDEX idx_notif_user_unread (user_id, read_at),
                INDEX idx_notif_user_created (user_id, created_at),
                INDEX idx_notif_subject (subject_type, subject_id),
                INDEX idx_notif_event (event_key),
                UNIQUE INDEX uniq_notif_dedupe (user_id, event_key, dedupe_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE cp_notifications ADD CONSTRAINT FK_CP_NOTIF_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE cp_notifications ADD CONSTRAINT FK_CP_NOTIF_ACTOR FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$sm->tablesExist(['cp_notification_digest_queue'])) {
            $this->addSql('CREATE TABLE cp_notification_digest_queue (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                notification_id INT NOT NULL,
                bucket VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_digest_bucket_sent (bucket, sent_at),
                UNIQUE INDEX uniq_digest_notif (user_id, notification_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE cp_notification_digest_queue ADD CONSTRAINT FK_CP_DIGEST_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE cp_notification_digest_queue ADD CONSTRAINT FK_CP_DIGEST_NOTIF FOREIGN KEY (notification_id) REFERENCES cp_notifications (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_notification_digest_queue'])) {
            $this->addSql('DROP TABLE cp_notification_digest_queue');
        }
        if ($sm->tablesExist(['cp_notifications'])) {
            $this->addSql('DROP TABLE cp_notifications');
        }
    }
}
