CREATE TABLE IF NOT EXISTS cp_forum_smilies (
    id INT AUTO_INCREMENT NOT NULL,
    code VARCHAR(64) NOT NULL,
    extra_codes JSON NOT NULL,
    title VARCHAR(128) NOT NULL,
    image_url VARCHAR(2048) DEFAULT NULL,
    emoji VARCHAR(16) DEFAULT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'default',
    sort_order INT NOT NULL DEFAULT 0,
    display_in_editor TINYINT(1) NOT NULL DEFAULT 1,
    imported_from VARCHAR(32) DEFAULT NULL,
    UNIQUE INDEX uniq_forum_smilie_code (code),
    INDEX idx_forum_smilie_sort (sort_order),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
