<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operator-editable mail bodies, one row per (template, language).
 *
 * The unique key on (template_key, locale) is the model: a template has exactly
 * one wording per language, and "which row wins" is never a question the
 * renderer has to answer at send time.
 */
final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_mail_templates (per-locale, operator-editable mail bodies).';
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
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_mail_templates (
            id INT AUTO_INCREMENT NOT NULL,
            template_key VARCHAR(100) NOT NULL,
            locale VARCHAR(5) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NOT NULL,
            body_text LONGTEXT DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_mail_template_key_locale (template_key, locale),
            INDEX idx_mail_template_key (template_key),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_mail_templates');
    }
}
