/*
    Forum Phase A schema. Same work as Version20260921220000.

    Guarded because this file and the core Doctrine migration can each arrive
    first: module install runs *.sql, existing sites run doctrine:migrations.
    information_schema checks keep the second pass a no-op.

    Block comments: ModuleInstallContext splits on ";" and discards any
    statement that starts with "--".
*/

CREATE TABLE IF NOT EXISTS cp_forum_user_stats (
    user_id INT NOT NULL,
    post_count INT NOT NULL DEFAULT 0,
    topic_count INT NOT NULL DEFAULT 0,
    like_received INT NOT NULL DEFAULT 0,
    last_posted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_forum_user_stats_posts (post_count),
    PRIMARY KEY(user_id),
    CONSTRAINT FK_FORUM_USER_STATS_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_board_stats (
    locale VARCHAR(5) NOT NULL,
    topic_count INT NOT NULL DEFAULT 0,
    post_count INT NOT NULL DEFAULT 0,
    topic_count_held INT NOT NULL DEFAULT 0,
    post_count_held INT NOT NULL DEFAULT 0,
    last_topic_id INT DEFAULT NULL,
    last_post_id INT DEFAULT NULL,
    last_post_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    last_poster_id INT DEFAULT NULL,
    last_poster_name VARCHAR(100) DEFAULT NULL,
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_FORUM_BOARD_STATS_POSTER (last_poster_id),
    PRIMARY KEY(locale),
    CONSTRAINT FK_FORUM_BOARD_STATS_POSTER FOREIGN KEY (last_poster_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_topic_view_buffer (
    id INT AUTO_INCREMENT NOT NULL,
    topic_id INT NOT NULL,
    seen_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_forum_view_buf_topic (topic_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_VIEW_BUF_TOPIC FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

SET @cp_fa_sec_path := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_sections' AND COLUMN_NAME = 'parent_path'
);
SET @cp_fa_sec_path_sql := IF(
    @cp_fa_sec_path = 0,
    'ALTER TABLE cp_forum_sections ADD COLUMN parent_path VARCHAR(255) NOT NULL DEFAULT ''''',
    'DO 0'
);
PREPARE cp_fa_sec_path_stmt FROM @cp_fa_sec_path_sql;
EXECUTE cp_fa_sec_path_stmt;
DEALLOCATE PREPARE cp_fa_sec_path_stmt;

SET @cp_fa_sec_buckets := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_sections' AND COLUMN_NAME = 'topic_count_held'
);
SET @cp_fa_sec_buckets_sql := IF(
    @cp_fa_sec_buckets = 0,
    'ALTER TABLE cp_forum_sections ADD COLUMN topic_count_held INT NOT NULL DEFAULT 0, ADD COLUMN topic_count_deleted INT NOT NULL DEFAULT 0, ADD COLUMN post_count_held INT NOT NULL DEFAULT 0, ADD COLUMN post_count_deleted INT NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fa_sec_buckets_stmt FROM @cp_fa_sec_buckets_sql;
EXECUTE cp_fa_sec_buckets_stmt;
DEALLOCATE PREPARE cp_fa_sec_buckets_stmt;

SET @cp_fa_sec_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_sections' AND INDEX_NAME = 'idx_forum_section_parent_path'
);
SET @cp_fa_sec_idx_sql := IF(
    @cp_fa_sec_idx = 0,
    'ALTER TABLE cp_forum_sections ADD INDEX idx_forum_section_parent_path (parent_path)',
    'DO 0'
);
PREPARE cp_fa_sec_idx_stmt FROM @cp_fa_sec_idx_sql;
EXECUTE cp_fa_sec_idx_stmt;
DEALLOCATE PREPARE cp_fa_sec_idx_stmt;

SET @cp_fa_top_held := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND COLUMN_NAME = 'post_count_held'
);
SET @cp_fa_top_held_sql := IF(
    @cp_fa_top_held = 0,
    'ALTER TABLE cp_forum_topics ADD COLUMN post_count_held INT NOT NULL DEFAULT 0, ADD COLUMN post_count_deleted INT NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fa_top_held_stmt FROM @cp_fa_top_held_sql;
EXECUTE cp_fa_top_held_stmt;
DEALLOCATE PREPARE cp_fa_top_held_stmt;

SET @cp_fa_top_del := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND COLUMN_NAME = 'deleted_at'
);
SET @cp_fa_top_del_sql := IF(
    @cp_fa_top_del = 0,
    'ALTER TABLE cp_forum_topics ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT ''(DC2Type:datetime_immutable)'', ADD COLUMN deleted_by_id INT DEFAULT NULL, ADD COLUMN delete_reason VARCHAR(255) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fa_top_del_stmt FROM @cp_fa_top_del_sql;
EXECUTE cp_fa_top_del_stmt;
DEALLOCATE PREPARE cp_fa_top_del_stmt;

SET @cp_fa_top_flags := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND COLUMN_NAME = 'has_attachment'
);
SET @cp_fa_top_flags_sql := IF(
    @cp_fa_top_flags = 0,
    'ALTER TABLE cp_forum_topics ADD COLUMN has_attachment TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN is_reported TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE cp_fa_top_flags_stmt FROM @cp_fa_top_flags_sql;
EXECUTE cp_fa_top_flags_stmt;
DEALLOCATE PREPARE cp_fa_top_flags_stmt;

SET @cp_fa_top_list := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_section_list'
);
SET @cp_fa_top_list_sql := IF(
    @cp_fa_top_list = 0,
    'ALTER TABLE cp_forum_topics ADD INDEX idx_forum_topic_section_list (section_id, discussion_state, sticky, last_post_date)',
    'DO 0'
);
PREPARE cp_fa_top_list_stmt FROM @cp_fa_top_list_sql;
EXECUTE cp_fa_top_list_stmt;
DEALLOCATE PREPARE cp_fa_top_list_stmt;

SET @cp_fa_top_del_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_deleted_by'
);
SET @cp_fa_top_del_idx_sql := IF(
    @cp_fa_top_del_idx = 0,
    'ALTER TABLE cp_forum_topics ADD INDEX idx_forum_topic_deleted_by (deleted_by_id)',
    'DO 0'
);
PREPARE cp_fa_top_del_idx_stmt FROM @cp_fa_top_del_idx_sql;
EXECUTE cp_fa_top_del_idx_stmt;
DEALLOCATE PREPARE cp_fa_top_del_idx_stmt;

SET @cp_fa_top_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'FK_FORUM_TOPIC_DELETED_BY'
);
SET @cp_fa_top_fk_sql := IF(
    @cp_fa_top_fk = 0,
    'ALTER TABLE cp_forum_topics ADD CONSTRAINT FK_FORUM_TOPIC_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE cp_fa_top_fk_stmt FROM @cp_fa_top_fk_sql;
EXECUTE cp_fa_top_fk_stmt;
DEALLOCATE PREPARE cp_fa_top_fk_stmt;

SET @cp_fa_post_del := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND COLUMN_NAME = 'deleted_at'
);
SET @cp_fa_post_del_sql := IF(
    @cp_fa_post_del = 0,
    'ALTER TABLE cp_forum_posts ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT ''(DC2Type:datetime_immutable)'', ADD COLUMN deleted_by_id INT DEFAULT NULL, ADD COLUMN delete_reason VARCHAR(255) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fa_post_del_stmt FROM @cp_fa_post_del_sql;
EXECUTE cp_fa_post_del_stmt;
DEALLOCATE PREPARE cp_fa_post_del_stmt;

SET @cp_fa_post_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts' AND INDEX_NAME = 'idx_forum_post_deleted_by'
);
SET @cp_fa_post_idx_sql := IF(
    @cp_fa_post_idx = 0,
    'ALTER TABLE cp_forum_posts ADD INDEX idx_forum_post_deleted_by (deleted_by_id)',
    'DO 0'
);
PREPARE cp_fa_post_idx_stmt FROM @cp_fa_post_idx_sql;
EXECUTE cp_fa_post_idx_stmt;
DEALLOCATE PREPARE cp_fa_post_idx_stmt;

SET @cp_fa_post_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_posts'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'FK_FORUM_POST_DELETED_BY'
);
SET @cp_fa_post_fk_sql := IF(
    @cp_fa_post_fk = 0,
    'ALTER TABLE cp_forum_posts ADD CONSTRAINT FK_FORUM_POST_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE cp_fa_post_fk_stmt FROM @cp_fa_post_fk_sql;
EXECUTE cp_fa_post_fk_stmt;
DEALLOCATE PREPARE cp_fa_post_fk_stmt;

DELETE m1 FROM cp_forum_read_markers m1
INNER JOIN cp_forum_read_markers m2
   ON m1.user_id = m2.user_id
  AND m1.section_id <=> m2.section_id
  AND m1.id < m2.id;

SET @cp_fa_read_uniq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_read_markers' AND INDEX_NAME = 'uniq_forum_read_user_section'
);
SET @cp_fa_read_uniq_sql := IF(
    @cp_fa_read_uniq = 0,
    'ALTER TABLE cp_forum_read_markers ADD UNIQUE INDEX uniq_forum_read_user_section (user_id, section_id)',
    'DO 0'
);
PREPARE cp_fa_read_uniq_stmt FROM @cp_fa_read_uniq_sql;
EXECUTE cp_fa_read_uniq_stmt;
DEALLOCATE PREPARE cp_fa_read_uniq_stmt;

UPDATE cp_forum_sections
SET parent_path = CONCAT('/', id, '/')
WHERE parent_id IS NULL AND (parent_path = '' OR parent_path IS NULL);

UPDATE cp_forum_sections c
INNER JOIN cp_forum_sections p ON c.parent_id = p.id
SET c.parent_path = CONCAT(p.parent_path, c.id, '/')
WHERE c.parent_path = '' AND p.parent_path <> '';

UPDATE cp_forum_sections c
INNER JOIN cp_forum_sections p ON c.parent_id = p.id
SET c.parent_path = CONCAT(p.parent_path, c.id, '/')
WHERE c.parent_path = '' AND p.parent_path <> '';

UPDATE cp_forum_sections c
INNER JOIN cp_forum_sections p ON c.parent_id = p.id
SET c.parent_path = CONCAT(p.parent_path, c.id, '/')
WHERE c.parent_path = '' AND p.parent_path <> '';

UPDATE cp_forum_topics
SET deleted_at = updated_at
WHERE discussion_state = 'deleted' AND deleted_at IS NULL;

UPDATE cp_forum_posts
SET deleted_at = COALESCE(updated_at, created_at)
WHERE discussion_state = 'deleted' AND deleted_at IS NULL;

UPDATE cp_forum_topics t
SET has_attachment = 1
WHERE EXISTS (
    SELECT 1 FROM cp_forum_post_attachments a
    INNER JOIN cp_forum_posts p ON p.id = a.post_id
    WHERE p.topic_id = t.id
);

UPDATE cp_forum_topics t
SET is_reported = 1
WHERE EXISTS (
    SELECT 1 FROM cp_forum_post_reports r
    INNER JOIN cp_forum_posts p ON p.id = r.post_id
    WHERE p.topic_id = t.id AND r.status = 0
);
