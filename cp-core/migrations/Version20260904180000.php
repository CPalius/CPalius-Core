<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * System telemetry logs plus the IP ban list used by the AACP Ban IP action.
 */
final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates cp_system_telemetry_logs and cp_banned_ips for AACP security telemetry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_system_telemetry_logs (
                id BIGINT AUTO_INCREMENT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_id BIGINT DEFAULT NULL,
                request_method VARCHAR(10) NOT NULL,
                request_uri VARCHAR(1000) NOT NULL,
                user_agent VARCHAR(500) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                threat_score INT NOT NULL,
                details JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_telemetry_severity_created (severity, created_at),
                INDEX idx_telemetry_ip (ip_address),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cp_banned_ips (
                id INT AUTO_INCREMENT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                banned_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_banned_ip (ip_address),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_system_telemetry_logs');
        $this->addSql('DROP TABLE cp_banned_ips');
    }
}
