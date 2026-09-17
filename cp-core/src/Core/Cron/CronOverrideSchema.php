<?php

declare(strict_types=1);

namespace App\Core\Cron;

use Doctrine\DBAL\Connection;

/**
 * Creates cp_cron_overrides when it is not there yet.
 *
 * Same reasoning as MailTemplateSchema: this ships as a file patch to sites
 * with no shell, so the table has to be able to appear without a migration
 * being run by hand. The migration still ships for fresh installs; whichever
 * runs second finds the table already there and does nothing.
 *
 * @see \App\Core\Mail\Template\MailTemplateSchema the pattern this follows
 */
final class CronOverrideSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns true when the table exists afterwards. Never throws: a database
     * user without DDL rights keeps the shipped schedules, which is exactly the
     * behaviour the installation had before this feature.
     */
    public function ensure(): bool
    {
        if ($this->ensured) {
            return true;
        }

        try {
            $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_cron_overrides (
                id INT AUTO_INCREMENT NOT NULL,
                job_name VARCHAR(190) NOT NULL,
                cron_expression VARCHAR(100) DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_cron_override_job (job_name),
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
