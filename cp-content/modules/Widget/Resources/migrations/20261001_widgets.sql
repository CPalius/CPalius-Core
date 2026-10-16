/*
    Widget instances: one row per widget placed in an area (for example the "Recent posts" box in the
    blog sidebar). The type decides what it draws; settings and visibility are JSON.

    Kept aligned with Modules\Widget\Entity\WidgetInstance. Comments are block comments:
    ModuleInstallContext splits on ";" and discards "--" statements.
*/
CREATE TABLE IF NOT EXISTS cp_widgets (
    id INT AUTO_INCREMENT NOT NULL,
    area VARCHAR(64) NOT NULL,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    settings JSON NOT NULL,
    visibility JSON NOT NULL,
    locale VARCHAR(10) DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_widget_area_position (area, position),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
