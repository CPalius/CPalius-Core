/*
    Blog comments table, owned by the Blog module instead of the core migrations.

    Every statement is idempotent (CREATE TABLE IF NOT EXISTS, INSERT IGNORE), so this is safe on a site
    whose core migrations already created these tables. Block comments only.
*/
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `cp_blog_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `node_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `author_id` int DEFAULT NULL,
  `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `poster_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_blog_comment_node` (`node_id`),
  KEY `idx_blog_comment_status` (`status`),
  KEY `idx_blog_comment_parent` (`parent_id`),
  KEY `idx_blog_comment_author` (`author_id`),
  KEY `idx_blog_comment_node_status` (`node_id`,`status`),
  CONSTRAINT `FK_BLOG_COMMENT_AUTHOR` FOREIGN KEY (`author_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_BLOG_COMMENT_NODE` FOREIGN KEY (`node_id`) REFERENCES `cp_nodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_BLOG_COMMENT_PARENT` FOREIGN KEY (`parent_id`) REFERENCES `cp_blog_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
