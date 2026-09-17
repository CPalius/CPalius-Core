<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operator-created mail templates.
 *
 * Only their identity lives here — key, name, purpose. The wording stays in
 * cp_mail_templates with every other template's wording, one row per language,
 * so "what does this mail say in Turkish" keeps a single answer and a single
 * place to look.
 */
final class Version20260918110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_mail_custom_templates (operator-created, manually sent mail templates).';
    }

    /**
     * MySQL commits implicitly on DDL, which invalidates the savepoint the
     * migration runner opened around this migration; releasing it afterwards
     * then fails even though the table was created and the version recorded.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_mail_custom_templates (
            id INT AUTO_INCREMENT NOT NULL,
            template_key VARCHAR(100) NOT NULL,
            label VARCHAR(190) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_mail_custom_template_key (template_key),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_mail_custom_templates');
    }
}
