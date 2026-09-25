<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aggregated visitor counters, separate from cp_system_telemetry_logs.
 *
 * Page views used to get one row each in cp_system_telemetry_logs, the same
 * table security threat events log to — which is both why clearing telemetry
 * also wiped visitor history, and why the table grew one row per hit instead
 * of one per day. These three tables replace that: one row per day
 * (cp_visitor_daily_stats), one row per hour for the rolling 24h chart
 * (cp_visitor_hourly_stats, pruned after a week), and one row per IP-per-day
 * purely to detect "has this IP already been counted today" (cp_visitor_daily_seen_ips,
 * pruned after two days — nothing needs to ask that question about an older day).
 */
final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates cp_visitor_daily_stats, cp_visitor_hourly_stats and cp_visitor_daily_seen_ips for aggregated visitor counting, separate from cp_system_telemetry_logs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_visitor_daily_stats (
                stat_date DATE NOT NULL,
                total_views INT UNSIGNED NOT NULL DEFAULT 0,
                unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY(stat_date)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cp_visitor_hourly_stats (
                stat_date DATE NOT NULL,
                stat_hour TINYINT UNSIGNED NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY(stat_date, stat_hour)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cp_visitor_daily_seen_ips (
                stat_date DATE NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                PRIMARY KEY(stat_date, ip_address)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_visitor_daily_seen_ips');
        $this->addSql('DROP TABLE cp_visitor_hourly_stats');
        $this->addSql('DROP TABLE cp_visitor_daily_stats');
    }
}
