<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use Doctrine\DBAL\Connection;

/**
 * Creates cp_mail_custom_templates when it is not there yet.
 *
 * Same reasoning as MailTemplateSchema, which this table sits beside: the
 * feature ships as a file patch to hosts with no shell, so the table has to be
 * able to appear without anybody running a migration. The migration still ships
 * for fresh installs and `IF NOT EXISTS` makes the order irrelevant.
 *
 * Only the identity of a custom template lives here — its key, its name and
 * what it is for. The wording stays in cp_mail_templates with every other
 * template's wording, one row per language, because "what does this mail say in
 * Turkish" should have exactly one answer and one place to look.
 */
final class CustomMailTemplateSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns true when the table exists afterwards. Never throws: a database
     * user with no DDL rights keeps the shipped templates and simply cannot add
     * custom ones, which is a missing feature rather than a broken site.
     */
    public function ensure(): bool
    {
        if ($this->ensured) {
            return true;
        }

        try {
            $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_mail_custom_templates (
                id INT AUTO_INCREMENT NOT NULL,
                template_key VARCHAR(100) NOT NULL,
                label VARCHAR(190) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_mail_custom_template_key (template_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            return $this->ensured = true;
        } catch (\Throwable) {
            // Marked done regardless: retrying a refused DDL statement once per
            // request forever turns a permissions problem into a slow site.
            $this->ensured = true;

            return false;
        }
    }
}
