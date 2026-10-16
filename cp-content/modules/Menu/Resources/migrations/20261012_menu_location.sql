/*
    A menu can be assigned to one theme or module location (main_header, footer_primary, ...).
    Guarded with information_schema, as in 20261002_menu_item_attributes.sql. Block comments only.
*/
SET @cp_menu_loc_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cp_menu_menus'
      AND COLUMN_NAME = 'location'
);
SET @cp_menu_loc_sql := IF(
    @cp_menu_loc_col = 0,
    'ALTER TABLE cp_menu_menus ADD COLUMN location VARCHAR(60) DEFAULT NULL, ADD UNIQUE INDEX uniq_menu_location (location)',
    'DO 0'
);
PREPARE cp_menu_add_location FROM @cp_menu_loc_sql;
EXECUTE cp_menu_add_location;
DEALLOCATE PREPARE cp_menu_add_location;
