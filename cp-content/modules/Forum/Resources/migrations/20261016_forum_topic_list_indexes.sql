/*
    Indexes for the forum's "top N" lists and for a section's topic page.

    Measured on 20 000 topics with cp:perf:seed + cp:perf:probe: the popular / latest / newest
    topic cards on the forum front page each took ~37 ms (index lookup on moved_to_topic_id, then a
    filesort of ~10 000 rows), and a section page took ~29 ms for its list plus ~16 ms for its count
    (an intersection of five single-column indexes). With these they are index-ordered scans that stop
    after LIMIT rows: 0.13 ms, 0.05 ms and 0.9 ms.

    Each statement checks information_schema first, so running this twice is harmless.
*/

-- popular topics
SET @cp_ft_ix0 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_top_views'
);
SET @cp_ft_ix0_sql := IF(
    @cp_ft_ix0 = 0,
    'CREATE INDEX idx_forum_topic_top_views ON cp_forum_topics (locale, discussion_state, mode, view_count, post_count, updated_at)',
    'DO 0'
);
PREPARE cp_ft_ix0_stmt FROM @cp_ft_ix0_sql;
EXECUTE cp_ft_ix0_stmt;
DEALLOCATE PREPARE cp_ft_ix0_stmt;

-- latest activity
SET @cp_ft_ix1 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_top_active'
);
SET @cp_ft_ix1_sql := IF(
    @cp_ft_ix1 = 0,
    'CREATE INDEX idx_forum_topic_top_active ON cp_forum_topics (locale, discussion_state, mode, last_post_date, updated_at)',
    'DO 0'
);
PREPARE cp_ft_ix1_stmt FROM @cp_ft_ix1_sql;
EXECUTE cp_ft_ix1_stmt;
DEALLOCATE PREPARE cp_ft_ix1_stmt;

-- newest topics
SET @cp_ft_ix2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_top_new'
);
SET @cp_ft_ix2_sql := IF(
    @cp_ft_ix2 = 0,
    'CREATE INDEX idx_forum_topic_top_new ON cp_forum_topics (locale, discussion_state, mode, created_at)',
    'DO 0'
);
PREPARE cp_ft_ix2_stmt FROM @cp_ft_ix2_sql;
EXECUTE cp_ft_ix2_stmt;
DEALLOCATE PREPARE cp_ft_ix2_stmt;

-- a section's topic list and its counts
SET @cp_ft_ix3 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'idx_forum_topic_section_page'
);
SET @cp_ft_ix3_sql := IF(
    @cp_ft_ix3 = 0,
    'CREATE INDEX idx_forum_topic_section_page ON cp_forum_topics (section_id, discussion_state, mode, locale, sticky, last_post_date, updated_at, moved_to_topic_id)',
    'DO 0'
);
PREPARE cp_ft_ix3_stmt FROM @cp_ft_ix3_sql;
EXECUTE cp_ft_ix3_stmt;
DEALLOCATE PREPARE cp_ft_ix3_stmt;

