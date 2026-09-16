CREATE TABLE IF NOT EXISTS cp_forum_link_previews (
    id INT AUTO_INCREMENT NOT NULL,
    url_hash VARCHAR(64) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    description VARCHAR(512) DEFAULT NULL,
    image_url VARCHAR(2048) DEFAULT NULL,
    site_name VARCHAR(255) DEFAULT NULL,
    favicon_url VARCHAR(512) DEFAULT NULL,
    status VARCHAR(16) NOT NULL,
    fetched_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_forum_link_preview_hash (url_hash),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
