<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Platform contracts: isolated async queue, outbound webhook subscriptions and
 * the machine-API idempotency cache. Modules never create these tables.
 */
final class Version20260910010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform contracts: async job queue, webhook subscriptions, API idempotency store.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if (!$sm->tablesExist(['cp_async_jobs'])) {
            $this->addSql('CREATE TABLE cp_async_jobs (
                id INT AUTO_INCREMENT NOT NULL,
                type VARCHAR(64) NOT NULL,
                payload JSON NOT NULL,
                tenant_id VARCHAR(64) DEFAULT NULL,
                attempts INT NOT NULL,
                available_at DATETIME NOT NULL,
                processed_at DATETIME DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                last_error VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_async_jobs_due (processed_at, available_at),
                INDEX idx_async_jobs_type (type),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$sm->tablesExist(['cp_webhook_subscriptions'])) {
            $this->addSql('CREATE TABLE cp_webhook_subscriptions (
                id INT AUTO_INCREMENT NOT NULL,
                label VARCHAR(191) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                secret_cipher LONGTEXT NOT NULL,
                secret_last4 VARCHAR(4) NOT NULL,
                events JSON NOT NULL,
                active TINYINT(1) NOT NULL,
                consecutive_failures INT NOT NULL,
                quarantined_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$sm->tablesExist(['cp_api_idempotency'])) {
            $this->addSql('CREATE TABLE cp_api_idempotency (
                id INT AUTO_INCREMENT NOT NULL,
                scope_hash VARCHAR(64) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                status_code INT NOT NULL,
                response_body LONGTEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_api_idempotency_scope (scope_hash),
                INDEX idx_api_idempotency_expires (expires_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['cp_api_idempotency'])) {
            $this->addSql('DROP TABLE cp_api_idempotency');
        }
        if ($sm->tablesExist(['cp_webhook_subscriptions'])) {
            $this->addSql('DROP TABLE cp_webhook_subscriptions');
        }
        if ($sm->tablesExist(['cp_async_jobs'])) {
            $this->addSql('DROP TABLE cp_async_jobs');
        }
    }
}
