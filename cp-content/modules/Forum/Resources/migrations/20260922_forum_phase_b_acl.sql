/*
    Forum Phase B schema. Same work as Version20260922010000.

    Guarded because this file and the core Doctrine migration can each arrive
    first. Block comments: ModuleInstallContext discards "--" statements.
*/

SET @cp_fb_effect := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_node_permissions' AND COLUMN_NAME = 'effect'
);
SET @cp_fb_effect_sql := IF(
    @cp_fb_effect = 0,
    'ALTER TABLE cp_forum_node_permissions ADD COLUMN effect VARCHAR(8) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_fb_effect_stmt FROM @cp_fb_effect_sql;
EXECUTE cp_fb_effect_stmt;
DEALLOCATE PREPARE cp_fb_effect_stmt;

SET @cp_fb_allowed := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_node_permissions' AND COLUMN_NAME = 'allowed'
);
SET @cp_fb_allowed_upd := IF(
    @cp_fb_allowed = 0,
    'DO 0',
    'UPDATE cp_forum_node_permissions SET effect = IF(allowed = 1, ''allow'', ''deny'') WHERE effect IS NULL OR effect = '''''
);
PREPARE cp_fb_allowed_upd_stmt FROM @cp_fb_allowed_upd;
EXECUTE cp_fb_allowed_upd_stmt;
DEALLOCATE PREPARE cp_fb_allowed_upd_stmt;

SET @cp_fb_allowed_drop := IF(
    @cp_fb_allowed = 0,
    'DO 0',
    'ALTER TABLE cp_forum_node_permissions DROP COLUMN allowed'
);
PREPARE cp_fb_allowed_drop_stmt FROM @cp_fb_allowed_drop;
EXECUTE cp_fb_allowed_drop_stmt;
DEALLOCATE PREPARE cp_fb_allowed_drop_stmt;

UPDATE cp_forum_node_permissions SET effect = 'inherit' WHERE effect IS NULL OR effect = '';
ALTER TABLE cp_forum_node_permissions MODIFY effect VARCHAR(8) NOT NULL;

DELETE p FROM cp_forum_node_permissions p
INNER JOIN cp_forum_node_permissions n
   ON p.section_id = n.section_id AND p.role_key = n.role_key
WHERE p.permission_key = 'poll' AND n.permission_key = 'poll_create';
UPDATE cp_forum_node_permissions SET permission_key = 'poll_create' WHERE permission_key = 'poll';

SET @cp_fb_key_len := (
    SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cp_forum_node_permissions' AND COLUMN_NAME = 'permission_key'
);
SET @cp_fb_key_sql := IF(
    @cp_fb_key_len IS NULL OR @cp_fb_key_len >= 64,
    'DO 0',
    'ALTER TABLE cp_forum_node_permissions MODIFY permission_key VARCHAR(64) NOT NULL'
);
PREPARE cp_fb_key_stmt FROM @cp_fb_key_sql;
EXECUTE cp_fb_key_stmt;
DEALLOCATE PREPARE cp_fb_key_stmt;

CREATE TABLE IF NOT EXISTS cp_forum_user_permissions (
    id INT AUTO_INCREMENT NOT NULL,
    section_id INT NOT NULL DEFAULT 0,
    user_id INT NOT NULL,
    permission_key VARCHAR(64) NOT NULL,
    effect VARCHAR(8) NOT NULL,
    UNIQUE INDEX uniq_fup_section_user_perm (section_id, user_id, permission_key),
    INDEX idx_fup_user (user_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_USER_PERM_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_moderators (
    id INT AUTO_INCREMENT NOT NULL,
    section_id INT NOT NULL,
    subject_type VARCHAR(8) NOT NULL,
    subject_id INT NOT NULL,
    subject_key VARCHAR(32) NOT NULL DEFAULT '',
    inherit_children TINYINT(1) NOT NULL DEFAULT 1,
    grant_keys LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    created_by_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_mod_slot (section_id, subject_type, subject_id, subject_key),
    INDEX IDX_FORUM_MOD_SECTION (section_id),
    INDEX IDX_FORUM_MOD_CREATED_BY (created_by_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_MOD_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE,
    CONSTRAINT FK_FORUM_MOD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_moderator_cache (
    id INT AUTO_INCREMENT NOT NULL,
    section_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    display_name VARCHAR(100) NOT NULL,
    subject_type VARCHAR(8) NOT NULL,
    display_on_index TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE INDEX uniq_fmc_section_user (section_id, user_id, subject_type, display_name),
    INDEX IDX_FORUM_MOD_CACHE_SECTION (section_id),
    INDEX IDX_FORUM_MOD_CACHE_USER (user_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_MOD_CACHE_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE,
    CONSTRAINT FK_FORUM_MOD_CACHE_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_permission_roles (
    id INT AUTO_INCREMENT NOT NULL,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    scope VARCHAR(16) NOT NULL,
    UNIQUE INDEX uniq_forum_perm_role_code (code),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_forum_permission_role_grants (
    id INT AUTO_INCREMENT NOT NULL,
    role_id INT NOT NULL,
    permission_key VARCHAR(64) NOT NULL,
    effect VARCHAR(8) NOT NULL,
    UNIQUE INDEX uniq_forum_perm_role_grant (role_id, permission_key),
    INDEX IDX_FORUM_PERM_ROLE_GRANT_ROLE (role_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_FORUM_PERM_ROLE_GRANT_ROLE FOREIGN KEY (role_id) REFERENCES cp_forum_permission_roles (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

INSERT IGNORE INTO cp_forum_permission_roles (code, label, scope) VALUES
    ('read_only', 'Read Only', 'content'),
    ('standard_member', 'Standard Member', 'content'),
    ('standard_local_mod', 'Standard Local Mod', 'moderate');

INSERT IGNORE INTO cp_forum_permission_role_grants (role_id, permission_key, effect)
SELECT r.id, v.permission_key, 'allow'
FROM cp_forum_permission_roles r
INNER JOIN (
    SELECT 'read_only' AS code, 'view' AS permission_key
    UNION ALL SELECT 'read_only', 'download'
    UNION ALL SELECT 'read_only', 'search'
    UNION ALL SELECT 'read_only', 'subscribe'
    UNION ALL SELECT 'standard_member', 'view'
    UNION ALL SELECT 'standard_member', 'thread_create'
    UNION ALL SELECT 'standard_member', 'reply'
    UNION ALL SELECT 'standard_member', 'upload'
    UNION ALL SELECT 'standard_member', 'download'
    UNION ALL SELECT 'standard_member', 'poll_create'
    UNION ALL SELECT 'standard_member', 'poll_vote'
    UNION ALL SELECT 'standard_member', 'search'
    UNION ALL SELECT 'standard_member', 'subscribe'
    UNION ALL SELECT 'standard_member', 'edit_own'
    UNION ALL SELECT 'standard_member', 'delete_own'
    UNION ALL SELECT 'standard_local_mod', 'moderate.approve'
    UNION ALL SELECT 'standard_local_mod', 'moderate.restore'
    UNION ALL SELECT 'standard_local_mod', 'moderate.view_held'
    UNION ALL SELECT 'standard_local_mod', 'moderate.view_deleted'
    UNION ALL SELECT 'standard_local_mod', 'moderate.view_ip'
    UNION ALL SELECT 'standard_local_mod', 'moderate.edit'
    UNION ALL SELECT 'standard_local_mod', 'moderate.delete'
    UNION ALL SELECT 'standard_local_mod', 'moderate.lock'
    UNION ALL SELECT 'standard_local_mod', 'moderate.sticky'
    UNION ALL SELECT 'standard_local_mod', 'moderate.move'
    UNION ALL SELECT 'standard_local_mod', 'moderate.merge'
    UNION ALL SELECT 'standard_local_mod', 'moderate.split'
    UNION ALL SELECT 'standard_local_mod', 'moderate.view_logs'
) v ON v.code = r.code;
