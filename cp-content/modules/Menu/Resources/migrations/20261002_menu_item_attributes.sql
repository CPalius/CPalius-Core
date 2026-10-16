/*
    Menu items can carry a rel value (nofollow, sponsored, ugc), an icon name and a CSS class.

    Guarded with information_schema rather than "ADD COLUMN IF NOT EXISTS" (MariaDB only), as in the
    Forum migrations; the module ledger already keeps this from running twice. Block comments only.
*/
SET @cp_menu_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cp_menu_items'
      AND COLUMN_NAME = 'css_class'
);
SET @cp_menu_sql := IF(
    @cp_menu_col = 0,
    'ALTER TABLE cp_menu_items ADD COLUMN rel VARCHAR(20) DEFAULT NULL, ADD COLUMN icon VARCHAR(60) DEFAULT NULL, ADD COLUMN css_class VARCHAR(100) DEFAULT NULL',
    'DO 0'
);
PREPARE cp_menu_add_attrs FROM @cp_menu_sql;
EXECUTE cp_menu_add_attrs;
DEALLOCATE PREPARE cp_menu_add_attrs;
