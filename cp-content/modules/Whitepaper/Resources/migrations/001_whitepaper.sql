-- The unique key on (locale, slug) is the anchor contract: the slug is the
-- fragment people have already linked to, so the database refuses two chapters
-- claiming the same one rather than letting the page render whichever row it
-- happened to load second.
CREATE TABLE IF NOT EXISTS cp_whitepaper_documents (
    id INT AUTO_INCREMENT NOT NULL,
    locale VARCHAR(10) NOT NULL,
    title VARCHAR(255) NOT NULL,
    intro_html LONGTEXT NOT NULL,
    version VARCHAR(32) NOT NULL,
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_whitepaper_document_locale (locale),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_whitepaper_sections (
    id INT AUTO_INCREMENT NOT NULL,
    locale VARCHAR(10) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    weight INT NOT NULL,
    body_html LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_whitepaper_section (locale, slug),
    INDEX idx_whitepaper_section_order (locale, weight),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
