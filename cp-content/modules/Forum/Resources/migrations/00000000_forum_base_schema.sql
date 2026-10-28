/*
    Forum base schema: the tables the core migrations used to create unconditionally. Runs before every other Forum file.

    Every statement is idempotent (CREATE TABLE IF NOT EXISTS, INSERT IGNORE), so this is safe on a site
    whose core migrations already created these tables. Block comments only.
*/
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `cp_forum_announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `ends_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_announcement_section` (`section_id`),
  KEY `idx_forum_announcement_window` (`is_active`,`starts_at`,`ends_at`),
  CONSTRAINT `FK_FORUM_ANN_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_ban_filters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_ban_filter_slot` (`type`,`rule`),
  KEY `idx_forum_ban_filter_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_bans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `type` smallint NOT NULL DEFAULT '1',
  `reason` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `expires_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `revoked_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_forum_ban_user` (`user_id`),
  KEY `IDX_FORUM_BAN_CREATED_BY` (`created_by_id`),
  KEY `idx_forum_ban_ip` (`ip_address`),
  KEY `idx_forum_ban_email` (`email`),
  CONSTRAINT `FK_FORUM_BAN_CREATED_BY` FOREIGN KEY (`created_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_BAN_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_board_stats` (
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `topic_count` int NOT NULL DEFAULT '0',
  `post_count` int NOT NULL DEFAULT '0',
  `topic_count_held` int NOT NULL DEFAULT '0',
  `post_count_held` int NOT NULL DEFAULT '0',
  `last_topic_id` int DEFAULT NULL,
  `last_post_id` int DEFAULT NULL,
  `last_post_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `last_poster_id` int DEFAULT NULL,
  `last_poster_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`locale`),
  KEY `IDX_FORUM_BOARD_STATS_POSTER` (`last_poster_id`),
  CONSTRAINT `FK_FORUM_BOARD_STATS_POSTER` FOREIGN KEY (`last_poster_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_censor_words` (
  `id` int NOT NULL AUTO_INCREMENT,
  `word` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `replacement` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_censor_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_drafts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `topic_id` int DEFAULT NULL,
  `section_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_draft_user` (`user_id`),
  KEY `idx_forum_draft_topic` (`topic_id`),
  KEY `idx_forum_draft_section` (`section_id`),
  CONSTRAINT `FK_FORUM_DRAFT_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_DRAFT_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_DRAFT_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_link_previews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `url_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `favicon_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fetched_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_link_preview_hash` (`url_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_moderation_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_id` int DEFAULT NULL,
  `action` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` int NOT NULL,
  `details` json NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_modlog_created` (`created_at`),
  KEY `idx_forum_modlog_target` (`target_type`,`target_id`),
  KEY `idx_forum_modlog_actor` (`actor_id`),
  CONSTRAINT `FK_FORUM_MODLOG_ACTOR` FOREIGN KEY (`actor_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_moderator_cache` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_on_index` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fmc_section_user` (`section_id`,`user_id`,`subject_type`,`display_name`),
  KEY `IDX_FORUM_MOD_CACHE_SECTION` (`section_id`),
  KEY `IDX_FORUM_MOD_CACHE_USER` (`user_id`),
  CONSTRAINT `FK_FORUM_MOD_CACHE_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_MOD_CACHE_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_moderators` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `subject_type` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` int NOT NULL,
  `subject_key` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `inherit_children` tinyint(1) NOT NULL DEFAULT '1',
  `grant_keys` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '(DC2Type:json)',
  `created_by_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_mod_slot` (`section_id`,`subject_type`,`subject_id`,`subject_key`),
  KEY `IDX_FORUM_MOD_SECTION` (`section_id`),
  KEY `IDX_FORUM_MOD_CREATED_BY` (`created_by_id`),
  CONSTRAINT `FK_FORUM_MOD_CREATED_BY` FOREIGN KEY (`created_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_MOD_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_node_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `role_key` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effect` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_FNP_SECTION_ROLE_PERM` (`section_id`,`role_key`,`permission_key`),
  KEY `IDX_FNP_SECTION` (`section_id`),
  CONSTRAINT `FK_FNP_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_permission_role_grants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int NOT NULL,
  `permission_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effect` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_perm_role_grant` (`role_id`,`permission_key`),
  KEY `IDX_FORUM_PERM_ROLE_GRANT_ROLE` (`role_id`),
  CONSTRAINT `FK_FORUM_PERM_ROLE_GRANT_ROLE` FOREIGN KEY (`role_id`) REFERENCES `cp_forum_permission_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_permission_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_perm_role_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_poll_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `poll_id` int NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL,
  `vote_count` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_forum_poll_opt_poll` (`poll_id`),
  CONSTRAINT `FK_FORUM_POLL_OPT_POLL` FOREIGN KEY (`poll_id`) REFERENCES `cp_forum_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_poll_votes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `poll_id` int NOT NULL,
  `option_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_poll_vote` (`poll_id`,`user_id`,`option_id`),
  KEY `idx_forum_poll_vote_user` (`user_id`),
  KEY `idx_forum_poll_vote_option` (`option_id`),
  CONSTRAINT `FK_FORUM_POLL_VOTE_OPTION` FOREIGN KEY (`option_id`) REFERENCES `cp_forum_poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_POLL_VOTE_POLL` FOREIGN KEY (`poll_id`) REFERENCES `cp_forum_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_POLL_VOTE_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_polls` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic_id` int NOT NULL,
  `question` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_choices` smallint NOT NULL,
  `hide_until_close` tinyint(1) NOT NULL,
  `closes_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `is_closed` tinyint(1) NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `allow_change` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_poll_topic` (`topic_id`),
  CONSTRAINT `FK_FORUM_POLL_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_post_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `download_count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_forum_attach_post` (`post_id`),
  KEY `idx_forum_attach_asset` (`asset_id`),
  CONSTRAINT `FK_FORUM_ATTACH_ASSET` FOREIGN KEY (`asset_id`) REFERENCES `cp_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_ATTACH_POST` FOREIGN KEY (`post_id`) REFERENCES `cp_forum_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_post_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `reporter_id` int DEFAULT NULL,
  `reporter_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` smallint NOT NULL DEFAULT '0',
  `resolved_by_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `resolved_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_post_report_status` (`status`),
  KEY `IDX_FORUM_POST_REPORT_POST` (`post_id`),
  KEY `IDX_FORUM_POST_REPORT_REPORTER` (`reporter_id`),
  KEY `IDX_FORUM_POST_REPORT_RESOLVED_BY` (`resolved_by_id`),
  CONSTRAINT `FK_FORUM_POST_REPORT_POST` FOREIGN KEY (`post_id`) REFERENCES `cp_forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_POST_REPORT_REPORTER` FOREIGN KEY (`reporter_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_POST_REPORT_RESOLVED_BY` FOREIGN KEY (`resolved_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_post_votes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `vote` smallint NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cp_forum_post_vote` (`post_id`,`user_id`),
  KEY `idx_cp_forum_post_vote_user` (`user_id`),
  CONSTRAINT `fk_cp_forum_post_vote_post` FOREIGN KEY (`post_id`) REFERENCES `cp_forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_forum_post_vote_user` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic_id` int NOT NULL,
  `section_id` int NOT NULL,
  `author_id` int DEFAULT NULL,
  `poster_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_by_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poster_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attach_count` int NOT NULL DEFAULT '0',
  `edit_count` int NOT NULL DEFAULT '0',
  `last_edit_date` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `discussion_state` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `deleted_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `deleted_by_id` int DEFAULT NULL,
  `delete_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edit_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edit_locked` tinyint(1) NOT NULL DEFAULT '0',
  `edited_by_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_forum_post_created` (`created_at`),
  KEY `idx_forum_post_topic` (`topic_id`,`id`),
  KEY `IDX_FORUM_POST_SECTION` (`section_id`),
  KEY `IDX_FORUM_POST_AUTHOR` (`author_id`),
  KEY `idx_forum_post_discussion_state` (`discussion_state`),
  KEY `idx_forum_post_deleted_by` (`deleted_by_id`),
  KEY `idx_forum_post_edited_by` (`edited_by_id`),
  CONSTRAINT `FK_FORUM_POST_AUTHOR` FOREIGN KEY (`author_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_POST_DELETED_BY` FOREIGN KEY (`deleted_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_POST_EDITED_BY` FOREIGN KEY (`edited_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_POST_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_POST_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_prefix_sections` (
  `prefix_id` int NOT NULL,
  `section_id` int NOT NULL,
  PRIMARY KEY (`prefix_id`,`section_id`),
  KEY `IDX_FPS_PREFIX` (`prefix_id`),
  KEY `IDX_FPS_SECTION` (`section_id`),
  CONSTRAINT `FK_FPS_PREFIX` FOREIGN KEY (`prefix_id`) REFERENCES `cp_forum_topic_prefixes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FPS_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_presence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `topic_id` int DEFAULT NULL,
  `last_seen_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `kind` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'guest',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_presence_session` (`session_hash`),
  KEY `idx_forum_presence_seen` (`last_seen_at`),
  KEY `idx_forum_presence_user` (`user_id`),
  KEY `idx_forum_presence_topic` (`topic_id`),
  KEY `idx_forum_presence_kind` (`kind`),
  CONSTRAINT `FK_FP_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FP_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_read_markers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `section_id` int DEFAULT NULL,
  `marked_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_read_marker` (`user_id`,`section_id`),
  UNIQUE KEY `uniq_forum_read_user_section` (`user_id`,`section_id`),
  KEY `idx_forum_read_section` (`section_id`),
  CONSTRAINT `FK_FORUM_READ_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_READ_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_container` tinyint(1) NOT NULL DEFAULT '0',
  `allow_topics` tinyint(1) NOT NULL DEFAULT '1',
  `topic_count` int NOT NULL DEFAULT '0',
  `post_count` int NOT NULL DEFAULT '0',
  `view_count` int NOT NULL DEFAULT '0',
  `last_topic_id` int DEFAULT NULL,
  `last_topic_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_post_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `last_poster_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `icon` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `section_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subcategory',
  `last_post_id` int DEFAULT NULL,
  `last_poster_id` int DEFAULT NULL,
  `node_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'forum',
  `link_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `required_capability` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `default_topic_sort` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'latest',
  `translation_group_id` binary(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
  `parent_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `topic_count_held` int NOT NULL DEFAULT '0',
  `topic_count_deleted` int NOT NULL DEFAULT '0',
  `post_count_held` int NOT NULL DEFAULT '0',
  `post_count_deleted` int NOT NULL DEFAULT '0',
  `rules_html` longtext COLLATE utf8mb4_unicode_ci,
  `access_secret_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_section_slug_locale` (`slug`,`locale`),
  UNIQUE KEY `uniq_forum_section_code_locale` (`code`,`locale`),
  UNIQUE KEY `uniq_forum_section_translation_group_locale` (`translation_group_id`,`locale`),
  KEY `idx_forum_section_locale` (`locale`),
  KEY `IDX_FORUM_SECTION_PARENT` (`parent_id`),
  KEY `idx_forum_section_node_type` (`node_type`),
  KEY `FK_FORUM_SECTION_LAST_POSTER` (`last_poster_id`),
  KEY `idx_forum_section_parent_path` (`parent_path`),
  CONSTRAINT `FK_FORUM_SECTION_LAST_POSTER` FOREIGN KEY (`last_poster_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_SECTION_PARENT` FOREIGN KEY (`parent_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_topic_prefixes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `css_class` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_topic_user_state` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `watching_since` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cp_forum_topic_user_state` (`topic_id`,`user_id`),
  KEY `idx_cp_forum_tus_topic` (`topic_id`),
  KEY `idx_cp_forum_tus_user` (`user_id`),
  CONSTRAINT `fk_cp_forum_tus_topic` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_forum_tus_user` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_topic_view_buffer` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic_id` int NOT NULL,
  `seen_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_view_buf_topic` (`topic_id`),
  CONSTRAINT `FK_FORUM_VIEW_BUF_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_topics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `moved_to_topic_id` int DEFAULT NULL,
  `first_poster_id` int DEFAULT NULL,
  `last_poster_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` smallint NOT NULL DEFAULT '0',
  `state` smallint NOT NULL DEFAULT '0',
  `sticky` tinyint(1) NOT NULL DEFAULT '0',
  `view_count` int NOT NULL DEFAULT '0',
  `post_count` int NOT NULL DEFAULT '0',
  `first_poster_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_poster_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `slug` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prefix_id` int DEFAULT NULL,
  `discussion_state` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `first_post_id` int DEFAULT NULL,
  `last_post_id` int DEFAULT NULL,
  `last_post_date` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_count_held` int NOT NULL DEFAULT '0',
  `post_count_deleted` int NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `deleted_by_id` int DEFAULT NULL,
  `delete_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_attachment` tinyint(1) NOT NULL DEFAULT '0',
  `is_reported` tinyint(1) NOT NULL DEFAULT '0',
  `translation_group_id` binary(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_topic_translation_group_locale` (`translation_group_id`,`locale`),
  KEY `idx_forum_topic_updated` (`updated_at`),
  KEY `idx_forum_topic_state` (`state`),
  KEY `idx_forum_topic_sticky` (`sticky`),
  KEY `IDX_FORUM_TOPIC_SECTION` (`section_id`),
  KEY `IDX_FORUM_TOPIC_MOVED` (`moved_to_topic_id`),
  KEY `IDX_FORUM_TOPIC_FIRST_POSTER` (`first_poster_id`),
  KEY `IDX_FORUM_TOPIC_LAST_POSTER` (`last_poster_id`),
  KEY `idx_forum_topic_slug` (`slug`),
  KEY `IDX_FORUM_TOPIC_PREFIX` (`prefix_id`),
  KEY `idx_forum_topic_discussion_state` (`discussion_state`),
  KEY `idx_forum_topic_last_post_date` (`last_post_date`),
  KEY `idx_forum_topic_locale` (`locale`),
  KEY `idx_forum_topic_section_list` (`section_id`,`discussion_state`,`sticky`,`last_post_date`),
  KEY `idx_forum_topic_deleted_by` (`deleted_by_id`),
  CONSTRAINT `FK_FORUM_TOPIC_DELETED_BY` FOREIGN KEY (`deleted_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_TOPIC_FIRST_POSTER` FOREIGN KEY (`first_poster_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_TOPIC_LAST_POSTER` FOREIGN KEY (`last_poster_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_TOPIC_MOVED` FOREIGN KEY (`moved_to_topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_TOPIC_PREFIX` FOREIGN KEY (`prefix_id`) REFERENCES `cp_forum_topic_prefixes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_TOPIC_SECTION` FOREIGN KEY (`section_id`) REFERENCES `cp_forum_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_topics_posted` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `topic_id` int NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_topics_posted` (`user_id`,`topic_id`),
  KEY `idx_forum_topics_posted_user` (`user_id`),
  KEY `idx_forum_topics_posted_topic` (`topic_id`),
  CONSTRAINT `FK_FORUM_POSTED_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_POSTED_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_user_blocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `blocked_id` int NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forum_user_block` (`user_id`,`blocked_id`),
  KEY `idx_forum_user_block_user` (`user_id`),
  KEY `idx_forum_user_block_blocked` (`blocked_id`),
  CONSTRAINT `FK_FORUM_BLOCK_BLOCKED` FOREIGN KEY (`blocked_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FORUM_BLOCK_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL DEFAULT '0',
  `user_id` int NOT NULL,
  `permission_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effect` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fup_section_user_perm` (`section_id`,`user_id`,`permission_key`),
  KEY `idx_fup_user` (`user_id`),
  CONSTRAINT `FK_FORUM_USER_PERM_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_user_ranks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_posts` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_user_reputations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_user_id` int NOT NULL,
  `to_user_id` int NOT NULL,
  `topic_id` int DEFAULT NULL,
  `post_id` int DEFAULT NULL,
  `value` smallint NOT NULL,
  `reason` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_rep_to` (`to_user_id`,`created_at`),
  KEY `idx_forum_rep_from` (`from_user_id`),
  KEY `idx_forum_rep_topic` (`topic_id`),
  KEY `IDX_FUR_POST` (`post_id`),
  CONSTRAINT `FK_FUR_FROM` FOREIGN KEY (`from_user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FUR_POST` FOREIGN KEY (`post_id`) REFERENCES `cp_forum_posts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FUR_TO` FOREIGN KEY (`to_user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FUR_TOPIC` FOREIGN KEY (`topic_id`) REFERENCES `cp_forum_topics` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_user_stats` (
  `user_id` int NOT NULL,
  `post_count` int NOT NULL DEFAULT '0',
  `topic_count` int NOT NULL DEFAULT '0',
  `like_received` int NOT NULL DEFAULT '0',
  `last_posted_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `warning_points` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`),
  KEY `idx_forum_user_stats_posts` (`post_count`),
  CONSTRAINT `FK_FORUM_USER_STATS_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cp_forum_warnings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `warned_by_id` int DEFAULT NULL,
  `points` int NOT NULL DEFAULT '1',
  `reason` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `idx_forum_warning_user` (`user_id`),
  KEY `idx_forum_warning_by` (`warned_by_id`),
  KEY `idx_forum_warning_expires` (`expires_at`),
  CONSTRAINT `FK_FORUM_WARN_BY` FOREIGN KEY (`warned_by_id`) REFERENCES `cp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_FORUM_WARN_USER` FOREIGN KEY (`user_id`) REFERENCES `cp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `cp_forum_topic_prefixes` (`id`, `label`, `color`, `sort_order`, `css_class`) VALUES (1,'Duyuru','#C0392B',0,'forum-prefix--announcement');
INSERT IGNORE INTO `cp_forum_topic_prefixes` (`id`, `label`, `color`, `sort_order`, `css_class`) VALUES (2,'Soru','#4AADE4',1,'forum-prefix--question');
INSERT IGNORE INTO `cp_forum_topic_prefixes` (`id`, `label`, `color`, `sort_order`, `css_class`) VALUES (3,'Çözüldü','#27AE60',2,NULL);
INSERT IGNORE INTO `cp_forum_topic_prefixes` (`id`, `label`, `color`, `sort_order`, `css_class`) VALUES (4,'Rehber','#2980B9',3,'forum-prefix--guide');
INSERT IGNORE INTO `cp_forum_user_ranks` (`id`, `label`, `color`, `icon`, `min_posts`, `sort_order`) VALUES (1,'Yeni Üye','#8B9DAF','bi-person',0,0);
INSERT IGNORE INTO `cp_forum_user_ranks` (`id`, `label`, `color`, `icon`, `min_posts`, `sort_order`) VALUES (2,'Üye','#4A7C9B','bi-person-check',10,1);
INSERT IGNORE INTO `cp_forum_user_ranks` (`id`, `label`, `color`, `icon`, `min_posts`, `sort_order`) VALUES (3,'Kıdemli Üye','#4AADE4','bi-star',50,2);
INSERT IGNORE INTO `cp_forum_user_ranks` (`id`, `label`, `color`, `icon`, `min_posts`, `sort_order`) VALUES (4,'Uzman','#C8A86E','bi-award',200,3);
INSERT IGNORE INTO `cp_forum_user_ranks` (`id`, `label`, `color`, `icon`, `min_posts`, `sort_order`) VALUES (5,'Moderatör','#27AE60','bi-shield-check',NULL,10);

SET FOREIGN_KEY_CHECKS = 1;
