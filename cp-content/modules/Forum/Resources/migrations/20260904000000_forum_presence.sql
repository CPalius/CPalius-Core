/*
    Who is looking at the forum right now.

    Guarded because this table has two authors: core migration
    Version20260906150000 creates it when it is missing, and this file creates it
    when the Forum module is installed. On a fresh site the core migrations run
    first, so an unguarded CREATE here would fail on a table that is already
    there and take the whole module install down with it. The module ledger only
    stops this file running twice; it knows nothing about what core already did.

    Block comments on purpose: ModuleInstallContext splits on ";" and DISCARDS
    any statement starting with "--", so a line comment above CREATE TABLE would
    silently drop that table from the install.
*/
CREATE TABLE IF NOT EXISTS cp_forum_presence (
    id INT AUTO_INCREMENT NOT NULL,
    session_hash VARCHAR(64) NOT NULL,
    user_id INT DEFAULT NULL,
    last_seen_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_presence_session (session_hash),
    INDEX idx_forum_presence_seen (last_seen_at),
    INDEX idx_forum_presence_user (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

/*
    Same reason, and MySQL 8 has no "ADD CONSTRAINT IF NOT EXISTS": adding the
    key a second time would leave two identical foreign keys, and two indexes,
    on one column. Both names are checked because core called it FK_FP_USER.
*/
SET @cp_fp_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cp_forum_presence'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME IN ('FK_FORUM_PRESENCE_USER', 'FK_FP_USER')
);
SET @cp_fp_sql := IF(
    @cp_fp_fk = 0,
    'ALTER TABLE cp_forum_presence ADD CONSTRAINT FK_FORUM_PRESENCE_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE cp_fp_add_fk FROM @cp_fp_sql;
EXECUTE cp_fp_add_fk;
DEALLOCATE PREPARE cp_fp_add_fk;
