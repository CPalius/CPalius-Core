<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hardening layer: expiring/CIDR IP bans, the session registry used for remote
 * revocation, and password reuse history.
 */
final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Security hardening: cp_banned_ips columns, cp_user_sessions, cp_password_history.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['cp_banned_ips'])) {
            $columns = [];
            foreach ($sm->listTableColumns('cp_banned_ips') as $column) {
                $columns[strtolower($column->getName())] = true;
            }

            if (!isset($columns['expires_at'])) {
                $this->addSql('ALTER TABLE cp_banned_ips ADD expires_at DATETIME DEFAULT NULL');
            }
            if (!isset($columns['reason'])) {
                $this->addSql('ALTER TABLE cp_banned_ips ADD reason VARCHAR(255) DEFAULT NULL');
            }
            if (!isset($columns['source'])) {
                $this->addSql("ALTER TABLE cp_banned_ips ADD source VARCHAR(16) NOT NULL DEFAULT 'manual'");
            }
            if (!isset($columns['is_range'])) {
                $this->addSql('ALTER TABLE cp_banned_ips ADD is_range TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!isset($columns['hit_count'])) {
                $this->addSql('ALTER TABLE cp_banned_ips ADD hit_count INT NOT NULL DEFAULT 0');
            }
            if (!isset($columns['last_hit_at'])) {
                $this->addSql('ALTER TABLE cp_banned_ips ADD last_hit_at DATETIME DEFAULT NULL');
            }

            $indexes = [];
            foreach ($sm->listTableIndexes('cp_banned_ips') as $index) {
                $indexes[strtolower($index->getName())] = true;
            }
            if (!isset($indexes['idx_banned_ip_expires'])) {
                $this->addSql('CREATE INDEX idx_banned_ip_expires ON cp_banned_ips (expires_at)');
            }
            if (!isset($indexes['idx_banned_ip_range'])) {
                $this->addSql('CREATE INDEX idx_banned_ip_range ON cp_banned_ips (is_range)');
            }
        }

        if (!$sm->tablesExist(['cp_user_sessions'])) {
            $this->addSql('CREATE TABLE cp_user_sessions (
                id BIGINT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                session_hash VARCHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_user_session_hash (session_hash),
                INDEX idx_user_session_user (user_id, last_seen_at),
                INDEX idx_user_session_revoked (revoked_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$sm->tablesExist(['cp_password_history'])) {
            $this->addSql('CREATE TABLE cp_password_history (
                id BIGINT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_password_history_user (user_id, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['cp_password_history'])) {
            $this->addSql('DROP TABLE cp_password_history');
        }
        if ($sm->tablesExist(['cp_user_sessions'])) {
            $this->addSql('DROP TABLE cp_user_sessions');
        }

        if ($sm->tablesExist(['cp_banned_ips'])) {
            foreach (['expires_at', 'reason', 'source', 'is_range', 'hit_count', 'last_hit_at'] as $column) {
                $this->addSql('ALTER TABLE cp_banned_ips DROP '.$column);
            }
        }
    }
}
