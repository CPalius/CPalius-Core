/*
    Aggregated visitor counters. Same three tables the core migration
    (cp-core/migrations/Version20260925130000.php, shipped before this
    module existed) already created on any site that applied 2.2.20 — kept
    here as CREATE TABLE IF NOT EXISTS so a fresh install with this module
    active gets them too, without needing that now-historical core migration
    to still exist. ModuleInstallContext splits this file on ";" and
    discards any line starting with "--", so comments are block comments.
*/
CREATE TABLE IF NOT EXISTS cp_visitor_daily_stats (
    stat_date DATE NOT NULL,
    total_views INT UNSIGNED NOT NULL DEFAULT 0,
    unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(stat_date)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_visitor_hourly_stats (
    stat_date DATE NOT NULL,
    stat_hour TINYINT UNSIGNED NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(stat_date, stat_hour)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_visitor_daily_seen_ips (
    stat_date DATE NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    PRIMARY KEY(stat_date, ip_address)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
