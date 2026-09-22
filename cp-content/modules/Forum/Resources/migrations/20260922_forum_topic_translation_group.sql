/*
    translation_group_id on forum topics so AI (and later Studio) can
    recognise an existing sibling instead of opening a duplicate thread.
*/

SET @cp_ft_tg := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND COLUMN_NAME = 'translation_group_id'
);
SET @cp_ft_tg_sql := IF(
    @cp_ft_tg = 0,
    'ALTER TABLE cp_forum_topics ADD COLUMN translation_group_id BINARY(16) DEFAULT NULL COMMENT ''(DC2Type:uuid)''',
    'DO 0'
);
PREPARE cp_ft_tg_stmt FROM @cp_ft_tg_sql;
EXECUTE cp_ft_tg_stmt;
DEALLOCATE PREPARE cp_ft_tg_stmt;

SET @cp_ft_tg_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_topics' AND INDEX_NAME = 'uniq_forum_topic_translation_group_locale'
);
SET @cp_ft_tg_idx_sql := IF(
    @cp_ft_tg_idx = 0,
    'CREATE UNIQUE INDEX uniq_forum_topic_translation_group_locale ON cp_forum_topics (translation_group_id, locale)',
    'DO 0'
);
PREPARE cp_ft_tg_idx_stmt FROM @cp_ft_tg_idx_sql;
EXECUTE cp_ft_tg_idx_stmt;
DEALLOCATE PREPARE cp_ft_tg_idx_stmt;
