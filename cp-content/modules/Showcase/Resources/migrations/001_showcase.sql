/*
    Showcase schema.

    This file is kept byte-compatible with what Doctrine generates from the
    entity mapping (`doctrine:schema:update --dump-sql`): same column types, same
    index names, same foreign-key names. That is not pedantry — a hand-written
    variation shows up forever as a diff in `doctrine:schema:validate`, and an
    operator cannot then tell a real drift from this module's noise.

    Two consequences of that rule are worth naming:

      - translation_group_id is BINARY(16), not CHAR(36). Symfony's UuidType
        stores UUIDs in binary here (see cp_terms), and a CHAR column would take
        the binary blob and quietly mangle every translation group.

      - No foreign key points at cp_assets. Gallery rows and the cover hold an
        asset id as a plain integer because the mapping does, and the code
        already treats a missing asset as "render nothing" rather than an error.

    Every table is module-owned and dropped by ModuleInstaller::uninstall(), so
    removing the module with "purge data" leaves nothing behind.

    Law 5.2 (multilingual integrity) is enforced by two COMPOSITE unique keys on
    cp_showcase_items: a slug is unique per locale, and a translation group holds
    at most one row per locale. Neither column is globally unique.

    Comments are block comments on purpose: ModuleInstallContext splits this file
    on ";" and DISCARDS any statement starting with "--", so a line comment above
    a CREATE TABLE would silently drop that table. Foreign keys are declared
    inline rather than through ALTER TABLE so that CREATE TABLE IF NOT EXISTS
    makes the whole file re-runnable.
*/
CREATE TABLE IF NOT EXISTS cp_showcase_types (
    id INT AUTO_INCREMENT NOT NULL,
    machine_name VARCHAR(32) NOT NULL,
    icon VARCHAR(64) NOT NULL,
    vocabulary VARCHAR(64) DEFAULT NULL,
    weight INT NOT NULL,
    enabled TINYINT(1) NOT NULL,
    settings JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_showcase_type_machine (machine_name),
    INDEX idx_showcase_type_enabled_weight (enabled, weight),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

/*
    A type's public name is content, not configuration: visitors read it, so it
    lives per locale instead of in a translation catalogue the site owner cannot
    edit from the panel.
*/
CREATE TABLE IF NOT EXISTS cp_showcase_type_translations (
    id INT AUTO_INCREMENT NOT NULL,
    locale VARCHAR(5) NOT NULL,
    label VARCHAR(191) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    type_id INT NOT NULL,
    INDEX IDX_8596E5CEC54C8C93 (type_id),
    UNIQUE INDEX uniq_showcase_type_locale (type_id, locale),
    PRIMARY KEY (id),
    CONSTRAINT FK_8596E5CEC54C8C93 FOREIGN KEY (type_id) REFERENCES cp_showcase_types (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_showcase_items (
    id INT AUTO_INCREMENT NOT NULL,
    locale VARCHAR(5) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    title VARCHAR(191) NOT NULL,
    summary VARCHAR(500) DEFAULT NULL,
    body LONGTEXT DEFAULT NULL,
    body_format VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    moderation_note VARCHAR(500) DEFAULT NULL,
    featured TINYINT(1) NOT NULL,
    price NUMERIC(14, 2) DEFAULT NULL,
    price_currency VARCHAR(3) DEFAULT NULL,
    price_mode VARCHAR(16) NOT NULL,
    external_url VARCHAR(500) DEFAULT NULL,
    demo_url VARCHAR(500) DEFAULT NULL,
    contact_email VARCHAR(191) DEFAULT NULL,
    contact_phone VARCHAR(40) DEFAULT NULL,
    location VARCHAR(191) DEFAULT NULL,
    cover_asset_id INT DEFAULT NULL,
    view_count INT NOT NULL,
    click_count INT NOT NULL,
    rating_sum INT NOT NULL,
    rating_count INT NOT NULL,
    data JSON NOT NULL,
    published_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL,
    translation_group_id BINARY(16) DEFAULT NULL,
    type_id INT NOT NULL,
    owner_id INT DEFAULT NULL,
    INDEX IDX_F3BB39F2C54C8C93 (type_id),
    INDEX IDX_F3BB39F27E3C61F9 (owner_id),
    INDEX idx_showcase_item_listing (type_id, locale, status, published_at),
    INDEX idx_showcase_item_owner (owner_id, status),
    INDEX idx_showcase_item_featured (featured, status),
    INDEX idx_showcase_item_deleted (deleted_at),
    UNIQUE INDEX uniq_showcase_item_slug_locale (slug, locale),
    UNIQUE INDEX uniq_showcase_item_group_locale (translation_group_id, locale),
    PRIMARY KEY (id),
    CONSTRAINT FK_F3BB39F2C54C8C93 FOREIGN KEY (type_id) REFERENCES cp_showcase_types (id),
    CONSTRAINT FK_F3BB39F27E3C61F9 FOREIGN KEY (owner_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_showcase_item_media (
    id INT AUTO_INCREMENT NOT NULL,
    asset_id INT NOT NULL,
    caption VARCHAR(191) DEFAULT NULL,
    weight INT NOT NULL,
    item_id INT NOT NULL,
    INDEX IDX_530F73D8126F525E (item_id),
    INDEX idx_showcase_media_item_weight (item_id, weight),
    UNIQUE INDEX uniq_showcase_media_item_asset (item_id, asset_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_530F73D8126F525E FOREIGN KEY (item_id) REFERENCES cp_showcase_items (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

/*
    Manifesto Law 6.3: per-type custom fields live in cp_showcase_items.data
    (JSON), and every field an editor marked "queryable" is flattened here so
    listing filters join an indexed table instead of scanning JSON.
*/
CREATE TABLE IF NOT EXISTS cp_showcase_item_index (
    id INT AUTO_INCREMENT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    value_string VARCHAR(255) DEFAULT NULL,
    value_int INT DEFAULT NULL,
    value_decimal NUMERIC(14, 2) DEFAULT NULL,
    value_datetime DATETIME DEFAULT NULL,
    item_id INT NOT NULL,
    INDEX IDX_B950B5D5126F525E (item_id),
    INDEX idx_showcase_index_string (field_name, value_string),
    INDEX idx_showcase_index_int (field_name, value_int),
    INDEX idx_showcase_index_decimal (field_name, value_decimal),
    INDEX idx_showcase_index_datetime (field_name, value_datetime),
    UNIQUE INDEX uniq_showcase_index_item_field (item_id, field_name),
    PRIMARY KEY (id),
    CONSTRAINT FK_B950B5D5126F525E FOREIGN KEY (item_id) REFERENCES cp_showcase_items (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_showcase_item_terms (
    item_id INT NOT NULL,
    term_id INT NOT NULL,
    INDEX IDX_B181EDA5126F525E (item_id),
    INDEX IDX_B181EDA5E2C35FC (term_id),
    PRIMARY KEY (item_id, term_id),
    CONSTRAINT FK_B181EDA5126F525E FOREIGN KEY (item_id) REFERENCES cp_showcase_items (id) ON DELETE CASCADE,
    CONSTRAINT FK_B181EDA5E2C35FC FOREIGN KEY (term_id) REFERENCES cp_terms (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

/*
    Cross-module links are stored as a ROUTE NAME plus parameters, never as a
    hard reference to another module's entity. A forum topic link keeps working
    when Forum is upgraded and disappears silently when Forum is deactivated,
    because Showcase never imports Modules\Forum or Modules\Blog.
*/
CREATE TABLE IF NOT EXISTS cp_showcase_links (
    id INT AUTO_INCREMENT NOT NULL,
    kind VARCHAR(24) NOT NULL,
    label VARCHAR(191) DEFAULT NULL,
    route_name VARCHAR(191) DEFAULT NULL,
    route_params JSON NOT NULL,
    url VARCHAR(500) DEFAULT NULL,
    weight INT NOT NULL,
    created_at DATETIME NOT NULL,
    item_id INT NOT NULL,
    INDEX IDX_C32771A7126F525E (item_id),
    INDEX idx_showcase_link_item_weight (item_id, weight),
    PRIMARY KEY (id),
    CONSTRAINT FK_C32771A7126F525E FOREIGN KEY (item_id) REFERENCES cp_showcase_items (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_showcase_reviews (
    id INT AUTO_INCREMENT NOT NULL,
    rating SMALLINT NOT NULL,
    body VARCHAR(2000) DEFAULT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    item_id INT NOT NULL,
    author_id INT DEFAULT NULL,
    INDEX IDX_8CFE9909126F525E (item_id),
    INDEX IDX_8CFE9909F675F31B (author_id),
    INDEX idx_showcase_review_item_status (item_id, status),
    UNIQUE INDEX uniq_showcase_review_item_author (item_id, author_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_8CFE9909126F525E FOREIGN KEY (item_id) REFERENCES cp_showcase_items (id) ON DELETE CASCADE,
    CONSTRAINT FK_8CFE9909F675F31B FOREIGN KEY (author_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
