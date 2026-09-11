<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ensures forum_presence exists and records the currently viewed thread.
 */
final class Version20260906150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates forum_presence if missing and adds topic_id for currently-reading presence.';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['forum_presence'])) {
            $this->addSql('CREATE TABLE forum_presence (
                id INT AUTO_INCREMENT NOT NULL,
                session_hash VARCHAR(64) NOT NULL,
                user_id INT DEFAULT NULL,
                topic_id INT DEFAULT NULL,
                last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_presence_session (session_hash),
                INDEX idx_forum_presence_seen (last_seen_at),
                INDEX idx_forum_presence_user (user_id),
                INDEX idx_forum_presence_topic (topic_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_presence ADD CONSTRAINT FK_FP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_presence ADD CONSTRAINT FK_FP_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE SET NULL');

            return;
        }

        $columns = array_change_key_case($schemaManager->listTableColumns('forum_presence'), CASE_LOWER);
        if (isset($columns['topic_id'])) {
            return;
        }

        $this->addSql('ALTER TABLE forum_presence ADD topic_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_forum_presence_topic ON forum_presence (topic_id)');
        $this->addSql('ALTER TABLE forum_presence ADD CONSTRAINT FK_FP_TOPIC FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['forum_presence'])) {
            return;
        }

        $columns = array_change_key_case($schemaManager->listTableColumns('forum_presence'), CASE_LOWER);
        if (!isset($columns['topic_id'])) {
            return;
        }

        $this->addSql('ALTER TABLE forum_presence DROP FOREIGN KEY FK_FP_TOPIC');
        $this->addSql('DROP INDEX idx_forum_presence_topic ON forum_presence');
        $this->addSql('ALTER TABLE forum_presence DROP topic_id');
    }
}
