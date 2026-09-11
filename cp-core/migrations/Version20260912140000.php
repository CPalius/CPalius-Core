<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T3.5 — application watchdog + outbound mail audit tables.
 */
final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_log_entries and cp_mail_logs for T3.5 logging.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if (!$sm->tablesExist(['cp_log_entries'])) {
            $this->addSql('CREATE TABLE cp_log_entries (
                id INT AUTO_INCREMENT NOT NULL,
                level VARCHAR(16) NOT NULL,
                channel VARCHAR(64) NOT NULL,
                message LONGTEXT NOT NULL,
                context JSON NOT NULL,
                extra JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_log_level_created (level, created_at),
                INDEX idx_log_channel_created (channel, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$sm->tablesExist(['cp_mail_logs'])) {
            $this->addSql('CREATE TABLE cp_mail_logs (
                id INT AUTO_INCREMENT NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                html_body LONGTEXT NOT NULL,
                text_body LONGTEXT DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                error LONGTEXT DEFAULT NULL,
                attempts INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_mail_log_status_created (status, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_mail_logs'])) {
            $this->addSql('DROP TABLE cp_mail_logs');
        }
        if ($sm->tablesExist(['cp_log_entries'])) {
            $this->addSql('DROP TABLE cp_log_entries');
        }
    }
}
