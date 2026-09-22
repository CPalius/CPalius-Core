<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sparse per-user capability overlay. Role YAML stays the default; these
 * rows are inherit/grant/deny exceptions read only by CPaliusVoter.
 */
final class Version20260923010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-user capability overrides (grant/deny on top of role YAML).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->createSchemaManager()->tablesExist(['cp_user_capability_overrides'])) {
            return;
        }

        $this->addSql(
            'CREATE TABLE cp_user_capability_overrides (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                capability VARCHAR(150) NOT NULL,
                effect VARCHAR(8) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_user_capability_override (user_id, capability),
                INDEX idx_user_capability_override_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_USER_CAP_OVERRIDE_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cp_user_capability_overrides');
    }
}
