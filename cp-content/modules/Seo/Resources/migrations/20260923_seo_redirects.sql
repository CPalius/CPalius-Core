/*
    Studio-owned 301/302 map for dead public URLs.

    Kept aligned with Modules\Seo\Entity\SeoRedirect. Comments are block
    comments: ModuleInstallContext splits on ";" and discards "--" statements.
*/
CREATE TABLE IF NOT EXISTS cp_seo_redirects (
    id INT AUTO_INCREMENT NOT NULL,
    source_path VARCHAR(255) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    status_code SMALLINT NOT NULL,
    is_active TINYINT(1) NOT NULL,
    hit_count INT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_seo_redirect_source (source_path),
    INDEX idx_seo_redirect_active (is_active),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
