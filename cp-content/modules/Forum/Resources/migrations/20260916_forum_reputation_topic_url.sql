-- Reputation can now cite a pasted link instead of a picked topic.
--
-- Guarded with information_schema rather than "ADD COLUMN IF NOT EXISTS":
-- MariaDB understands that clause and MySQL 8 does not, and this module runs on
-- both (production is MariaDB, development is MySQL 8). The module ledger
-- already stops this file running twice; the guard is for the installation that
-- had the column added by hand from a support thread.
SET @cp_rep_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'forum_user_reputations'
      AND COLUMN_NAME = 'topic_url'
);
SET @cp_rep_sql := IF(
    @cp_rep_col = 0,
    'ALTER TABLE forum_user_reputations ADD COLUMN topic_url VARCHAR(500) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_rep_add_topic_url FROM @cp_rep_sql;
EXECUTE cp_rep_add_topic_url;
DEALLOCATE PREPARE cp_rep_add_topic_url;
