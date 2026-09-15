<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use Doctrine\DBAL\Connection;

/**
 * Creates cp_mail_templates when it is not there yet.
 *
 * This exists because this feature ships as a file patch. A patch replaces
 * files and nothing else — it cannot run a Doctrine migration — so an
 * installation that takes the patch would otherwise have the code for mail
 * templates and no table for them until somebody found the update screen and
 * pressed Apply. On shared hosting with no shell that is a long time.
 *
 * The migration (Version20260916100000) still ships and is still the path a
 * fresh install takes; `CREATE TABLE IF NOT EXISTS` here and `IF NOT EXISTS`
 * there means whichever runs second does nothing.
 *
 * Called from the read path's failure branch and once before the admin screen
 * writes, never on every request: the cost is one DDL statement on the first
 * request after the patch lands, and nothing at all afterwards.
 */
final class MailTemplateSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns true when the table exists afterwards. Never throws — a site
     * whose database user may not issue DDL keeps working on the shipped
     * catalogue, which is exactly the behaviour it had before this feature.
     */
    public function ensure(): bool
    {
        if ($this->ensured) {
            return true;
        }

        try {
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

            return $this->ensured = true;
        } catch (\Throwable) {
            // Marked done regardless: retrying a DDL statement that the
            // database refused, once per request forever, would turn a
            // permissions problem into a performance one.
            $this->ensured = true;

            return false;
        }
    }
}
