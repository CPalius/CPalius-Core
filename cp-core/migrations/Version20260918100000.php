<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operator overrides for the code-declared cron jobs.
 *
 * #[CpCronJob] ships a schedule with the task, which is the right default but
 * not a decision the site is stuck with. One row per job name holds the
 * schedule and the on/off state an operator chose, so an update that rewrites
 * the attribute cannot quietly undo it.
 *
 * cron_expression is nullable on purpose: switching a task off without also
 * freezing its schedule at today's value has to be expressible.
 */
final class Version20260918100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_cron_overrides (operator schedule/state for code cron jobs).';
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
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_cron_overrides (
            id INT AUTO_INCREMENT NOT NULL,
            job_name VARCHAR(190) NOT NULL,
            cron_expression VARCHAR(100) DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_cron_override_job (job_name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_cron_overrides');
    }
}
