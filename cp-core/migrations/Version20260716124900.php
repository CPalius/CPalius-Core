<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716124900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AACP Cron Yonetimi: cp_cron_jobs + cp_cron_job_runs tablolari (DB-tabanli dinamik cron isleri).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cp_cron_job_runs (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, success TINYINT DEFAULT NULL, output LONGTEXT DEFAULT NULL, triggered_manually TINYINT NOT NULL, cron_job_id INT NOT NULL, INDEX IDX_338474B179099ED8 (cron_job_id), INDEX idx_cron_job_run_started_at (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cp_cron_jobs (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(191) NOT NULL, command_name VARCHAR(191) NOT NULL, command_arguments VARCHAR(500) DEFAULT NULL, cron_expression VARCHAR(100) NOT NULL, active TINYINT NOT NULL, last_run_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cp_cron_job_runs ADD CONSTRAINT FK_338474B179099ED8 FOREIGN KEY (cron_job_id) REFERENCES cp_cron_jobs (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cp_cron_job_runs DROP FOREIGN KEY FK_338474B179099ED8');
        $this->addSql('DROP TABLE cp_cron_job_runs');
        $this->addSql('DROP TABLE cp_cron_jobs');
    }
}
