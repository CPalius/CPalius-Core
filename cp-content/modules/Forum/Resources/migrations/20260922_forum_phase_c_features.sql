/*
    Forum Phase C schema. Same work as Version20260922020000.

    Guarded because this file and the core Doctrine migration can each arrive
    first. Block comments: ModuleInstallContext discards "--" statements.
*/

SET @cp_fc_stats_pts := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_user_stats' AND COLUMN_NAME = 'warning_points'
);
SET @cp_fc_stats_pts_sql := IF(
    @cp_fc_stats_pts = 0,
    'ALTER TABLE cp_forum_user_stats ADD COLUMN warning_points INT NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_stats_pts_stmt FROM @cp_fc_stats_pts_sql;
EXECUTE cp_fc_stats_pts_stmt;
DEALLOCATE PREPARE cp_fc_stats_pts_stmt;

SET @cp_fc_sec_rules := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_sections' AND COLUMN_NAME = 'rules_html'
);
SET @cp_fc_sec_rules_sql := IF(
    @cp_fc_sec_rules = 0,
    'ALTER TABLE cp_forum_sections ADD COLUMN rules_html LONGTEXT DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fc_sec_rules_stmt FROM @cp_fc_sec_rules_sql;
EXECUTE cp_fc_sec_rules_stmt;
DEALLOCATE PREPARE cp_fc_sec_rules_stmt;

SET @cp_fc_sec_hash := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_sections' AND COLUMN_NAME = 'access_secret_hash'
);
SET @cp_fc_sec_hash_sql := IF(
    @cp_fc_sec_hash = 0,
    'ALTER TABLE cp_forum_sections ADD COLUMN access_secret_hash VARCHAR(255) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fc_sec_hash_stmt FROM @cp_fc_sec_hash_sql;
EXECUTE cp_fc_sec_hash_stmt;
DEALLOCATE PREPARE cp_fc_sec_hash_stmt;

SET @cp_fc_post_reason := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND COLUMN_NAME = 'edit_reason'
);
SET @cp_fc_post_reason_sql := IF(
    @cp_fc_post_reason = 0,
    'ALTER TABLE cp_forum_posts ADD COLUMN edit_reason VARCHAR(255) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fc_post_reason_stmt FROM @cp_fc_post_reason_sql;
EXECUTE cp_fc_post_reason_stmt;
DEALLOCATE PREPARE cp_fc_post_reason_stmt;

SET @cp_fc_post_lock := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND COLUMN_NAME = 'edit_locked'
);
SET @cp_fc_post_lock_sql := IF(
    @cp_fc_post_lock = 0,
    'ALTER TABLE cp_forum_posts ADD COLUMN edit_locked TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_post_lock_stmt FROM @cp_fc_post_lock_sql;
EXECUTE cp_fc_post_lock_stmt;
DEALLOCATE PREPARE cp_fc_post_lock_stmt;

SET @cp_fc_post_editor := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND COLUMN_NAME = 'edited_by_id'
);
SET @cp_fc_post_editor_sql := IF(
    @cp_fc_post_editor = 0,
    'ALTER TABLE cp_forum_posts ADD COLUMN edited_by_id INT DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fc_post_editor_stmt FROM @cp_fc_post_editor_sql;
EXECUTE cp_fc_post_editor_stmt;
DEALLOCATE PREPARE cp_fc_post_editor_stmt;

SET @cp_fc_post_editor_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND INDEX_NAME = 'idx_forum_post_edited_by'
);
SET @cp_fc_post_editor_idx_sql := IF(
    @cp_fc_post_editor_idx = 0,
    'ALTER TABLE cp_forum_posts ADD INDEX idx_forum_post_edited_by (edited_by_id)',
    'DO 0'
);
PREPARE cp_fc_post_editor_idx_stmt FROM @cp_fc_post_editor_idx_sql;
EXECUTE cp_fc_post_editor_idx_stmt;
DEALLOCATE PREPARE cp_fc_post_editor_idx_stmt;

SET @cp_fc_post_editor_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'FK_FORUM_POST_EDITED_BY'
);
SET @cp_fc_post_editor_fk_sql := IF(
    @cp_fc_post_editor_fk = 0,
    'ALTER TABLE cp_forum_posts ADD CONSTRAINT FK_FORUM_POST_EDITED_BY FOREIGN KEY (edited_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE cp_fc_post_editor_fk_stmt FROM @cp_fc_post_editor_fk_sql;
EXECUTE cp_fc_post_editor_fk_stmt;
DEALLOCATE PREPARE cp_fc_post_editor_fk_stmt;

SET @cp_fc_att_dl := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_post_attachments' AND COLUMN_NAME = 'download_count'
);
SET @cp_fc_att_dl_sql := IF(
    @cp_fc_att_dl = 0,
    'ALTER TABLE cp_forum_post_attachments ADD COLUMN download_count INT NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_att_dl_stmt FROM @cp_fc_att_dl_sql;
EXECUTE cp_fc_att_dl_stmt;
DEALLOCATE PREPARE cp_fc_att_dl_stmt;

SET @cp_fc_poll_closed := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_polls' AND COLUMN_NAME = 'is_closed'
);
SET @cp_fc_poll_closed_sql := IF(
    @cp_fc_poll_closed = 0,
    'ALTER TABLE cp_forum_polls ADD COLUMN is_closed TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_poll_closed_stmt FROM @cp_fc_poll_closed_sql;
EXECUTE cp_fc_poll_closed_stmt;
DEALLOCATE PREPARE cp_fc_poll_closed_stmt;

SET @cp_fc_poll_public := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_polls' AND COLUMN_NAME = 'is_public'
);
SET @cp_fc_poll_public_sql := IF(
    @cp_fc_poll_public = 0,
    'ALTER TABLE cp_forum_polls ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_poll_public_stmt FROM @cp_fc_poll_public_sql;
EXECUTE cp_fc_poll_public_stmt;
DEALLOCATE PREPARE cp_fc_poll_public_stmt;

SET @cp_fc_poll_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_polls' AND COLUMN_NAME = 'allow_change'
);
SET @cp_fc_poll_change_sql := IF(
    @cp_fc_poll_change = 0,
    'ALTER TABLE cp_forum_polls ADD COLUMN allow_change TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fc_poll_change_stmt FROM @cp_fc_poll_change_sql;
EXECUTE cp_fc_poll_change_stmt;
DEALLOCATE PREPARE cp_fc_poll_change_stmt;

CREATE TABLE IF NOT EXISTS cp_forum_topics_posted (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    topic_id INT NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_topics_posted (user_id, topic_id),
    INDEX idx_forum_topics_posted_user (user_id),
    INDEX idx_forum_topics_posted_topic (topic_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_POSTED_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
    CONSTRAINT FK_FORUM_POSTED_TOPIC FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_announcements (
    id INT AUTO_INCREMENT NOT NULL,
    section_id INT DEFAULT NULL,
    body LONGTEXT NOT NULL,
    starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_forum_announcement_section (section_id),
    INDEX idx_forum_announcement_window (is_active, starts_at, ends_at),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_ANN_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_warnings (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    warned_by_id INT DEFAULT NULL,
    points INT NOT NULL DEFAULT 1,
    reason LONGTEXT NOT NULL,
    expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_forum_warning_user (user_id),
    INDEX idx_forum_warning_by (warned_by_id),
    INDEX idx_forum_warning_expires (expires_at),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_WARN_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
    CONSTRAINT FK_FORUM_WARN_BY FOREIGN KEY (warned_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_ban_filters (
    id INT AUTO_INCREMENT NOT NULL,
    type VARCHAR(8) NOT NULL,
    rule VARCHAR(255) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_ban_filter_slot (type, rule),
    INDEX idx_forum_ban_filter_type (type),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_user_blocks (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    blocked_id INT NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_user_block (user_id, blocked_id),
    INDEX idx_forum_user_block_user (user_id),
    INDEX idx_forum_user_block_blocked (blocked_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_BLOCK_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
    CONSTRAINT FK_FORUM_BLOCK_BLOCKED FOREIGN KEY (blocked_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

INSERT IGNORE INTO cp_forum_topics_posted (user_id, topic_id, created_at)
SELECT p.author_id, p.topic_id, MIN(p.created_at)
FROM cp_forum_posts p
WHERE p.author_id IS NOT NULL
GROUP BY p.author_id, p.topic_id;
