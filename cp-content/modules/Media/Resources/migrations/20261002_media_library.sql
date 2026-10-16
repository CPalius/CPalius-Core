/*
    Media library: folders, which folder an asset sits in, and per-user favourites/recents.

    cp_assets stays untouched (Law 2 — Core Never Dies: the core Asset table must work with this
    module off). An asset with no cp_media_placements row simply lives in the root folder.
    No foreign keys on purpose: deleted rows go to the recycle bin and come back by plain INSERT,
    so a constraint would make restoring a child before its parent fail for no good reason.
    Kept aligned with Modules\Media\Entity\*. Block comments only (see 20260923_seo_redirects.sql).
*/
CREATE TABLE IF NOT EXISTS cp_media_folders (
    id INT AUTO_INCREMENT NOT NULL,
    parent_id INT DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    color VARCHAR(16) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_media_folder_parent (parent_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_media_placements (
    asset_id INT NOT NULL,
    folder_id INT NOT NULL,
    INDEX idx_media_placement_folder (folder_id),
    PRIMARY KEY (asset_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_media_user_flags (
    user_id INT NOT NULL,
    asset_id INT NOT NULL,
    favorite TINYINT(1) DEFAULT 0 NOT NULL,
    last_opened_at DATETIME DEFAULT NULL,
    INDEX idx_media_flag_asset (asset_id),
    PRIMARY KEY (user_id, asset_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
