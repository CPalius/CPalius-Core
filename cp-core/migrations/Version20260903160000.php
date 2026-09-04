<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #[CpResource] Audit Log: creates cp_audit_logs, the append-only change
 * history written by App\Core\Audit\EventListener\AuditLogListener for every
 * entity marked #[CpResource(auditable: true)] or #[Auditable].
 *
 * resource_id is VARCHAR so composite and UUID identifiers fit; it is
 * nullable only for the brief window a create is captured before flush.
 * changes holds a {field: [old, new]} JSON diff.
 */
final class Version20260903160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates cp_audit_logs (resource_name, resource_id, user_id, action, changes JSON, created_at) for the #[CpResource] audit log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_audit_logs (
                id INT AUTO_INCREMENT NOT NULL,
                resource_name VARCHAR(100) NOT NULL,
                resource_id VARCHAR(128) DEFAULT NULL,
                user_id INT DEFAULT NULL,
                action VARCHAR(16) NOT NULL,
                changes JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_audit_resource (resource_name, resource_id),
                INDEX idx_audit_user (user_id),
                INDEX idx_audit_created_at (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_audit_logs');
    }
}
