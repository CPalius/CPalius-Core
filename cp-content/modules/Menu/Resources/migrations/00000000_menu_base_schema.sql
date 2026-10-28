/*
    Menu base schema: cp_menu_menus and cp_menu_items, so the module owns its tables.

    Every statement is idempotent (CREATE TABLE IF NOT EXISTS, INSERT IGNORE), so this is safe on a site
    whose core migrations already created these tables. Block comments only.
*/
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `cp_menu_menus` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_menu_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_menu_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `node_id` int DEFAULT NULL,
  `sort_order` int NOT NULL,
  `open_in_new_tab` tinyint NOT NULL,
  `menu_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `translation_group_id` binary(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_menu_item_translation_group_locale` (`translation_group_id`,`locale`),
  KEY `IDX_E079FA58CCD7E912` (`menu_id`),
  KEY `IDX_E079FA58727ACA70` (`parent_id`),
  KEY `idx_menu_item_parent` (`menu_id`,`parent_id`),
  CONSTRAINT `FK_70B2CA2A727ACA70` FOREIGN KEY (`parent_id`) REFERENCES `cp_menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_70B2CA2ACCD7E912` FOREIGN KEY (`menu_id`) REFERENCES `cp_menu_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
