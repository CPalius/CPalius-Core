<?php

declare(strict_types=1);

/**
 * The v2 table naming map — the single source of truth for the schema rename.
 *
 * Both the code rewriter (tools/schema/rename.php) and the Doctrine migration
 * read this file, so the database and the codebase cannot drift apart: there is
 * one list, and changing a name here changes it in both places.
 *
 * Naming rules
 * ------------
 *   core    cp_<noun>
 *   module  cp_<module>_<noun>
 *
 * The module rule is deliberately mechanical rather than pretty — a third-party
 * module author needs one sentence ("your tables are cp_<yourmodule>_*"), and
 * the occasional stutter (cp_menu_menus) is the price. Django and Rails engines
 * make the same trade.
 *
 * Framework-owned tables are absent on purpose: doctrine_migration_versions and
 * messenger_messages belong to Doctrine and Symfony Messenger, and renaming them
 * means teaching two libraries about a name they already hardcode.
 */

return [
    /*
     * RENAMES — same table, new name.
     */
    'renames' => [
        // Core: these predate the cp_ convention and never got it.
        'assets' => 'cp_assets',
        'nodes' => 'cp_nodes',
        'node_field_index' => 'cp_node_field_index',
        'users' => 'cp_users',
        // "url_aliases" and "cp_path_alias_patterns" are two halves of one
        // feature; aligning the noun makes that visible at a glance.
        'url_aliases' => 'cp_path_aliases',
        // Doctrine ManyToMany join tables — renamed, not merged; see the note
        // under 'merges' for why folding them together does not work.
        'node_category' => 'cp_node_categories',
        'node_tag' => 'cp_node_tags',

        // Menu module.
        'menus' => 'cp_menu_menus',
        'menu_items' => 'cp_menu_items',

        // Blog module.
        'blog_comments' => 'cp_blog_comments',

        // Roadmap module.
        'roadmap_entries' => 'cp_roadmap_entries',

        // Forum module.
        'forum_bans' => 'cp_forum_bans',
        'forum_censor_words' => 'cp_forum_censor_words',
        'forum_drafts' => 'cp_forum_drafts',
        'forum_link_previews' => 'cp_forum_link_previews',
        'forum_moderation_logs' => 'cp_forum_moderation_logs',
        'forum_node_permissions' => 'cp_forum_node_permissions',
        'forum_polls' => 'cp_forum_polls',
        'forum_poll_options' => 'cp_forum_poll_options',
        'forum_poll_votes' => 'cp_forum_poll_votes',
        'forum_posts' => 'cp_forum_posts',
        'forum_post_attachments' => 'cp_forum_post_attachments',
        'forum_post_reports' => 'cp_forum_post_reports',
        'forum_read_markers' => 'cp_forum_read_markers',
        'forum_prefix_sections' => 'cp_forum_prefix_sections',
        'forum_presence' => 'cp_forum_presence',
        'forum_sections' => 'cp_forum_sections',
        'forum_topics' => 'cp_forum_topics',
        'forum_topic_prefixes' => 'cp_forum_topic_prefixes',
        'forum_user_ranks' => 'cp_forum_user_ranks',
        'forum_user_reputations' => 'cp_forum_user_reputations',
    ],

    /*
     * MERGES — several tables of identical shape folded into one.
     *
     * Each source contributes its rows plus a constant discriminator, so no
     * information is lost and the old queries all have a direct translation.
     */
    'merges' => [
        'cp_forum_post_votes' => [
            // Identical shape, identical parent, opposite meaning — the only
            // difference between the two tables was which one you inserted into.
            //
            // Worth doing for more than the table count: like/dislike exclusivity
            // currently lives in ForumTopicService::toggleLike(), which deletes
            // the opposing row before inserting. Nothing in the database enforces
            // it, so two concurrent requests can leave a user holding both. One
            // table with UNIQUE(post_id, user_id) makes that unrepresentable.
            'discriminator' => 'vote',
            'sources' => [
                'forum_post_likes' => 1,
                'forum_post_dislikes' => -1,
            ],
        ],

        'cp_forum_topic_user_state' => [
            // Both are "what this user has going on with this topic", both hang
            // off ForumTopic, and a user has at most one row in each. They become
            // two nullable columns on one row: last_seen_at for the view, and
            // watching_since for the watch.
            'discriminator' => null,
            'sources' => [
                'forum_topic_views' => 'last_seen_at',
                'forum_topic_watches' => 'watching_since',
            ],
        ],
    ],

    /*
     * Merges considered and rejected, recorded so the next person does not have
     * to rediscover why the obvious consolidation is a bad one.
     *
     *   node_category + node_tag
     *     Surface-identical: both are node <-> term with two columns. But they
     *     are Doctrine ManyToMany join tables, and a join table is exactly two
     *     foreign keys — there is nowhere to put a constant discriminator. The
     *     merge would mean hand-rolling an association entity and filtering the
     *     collections in PHP, which is a lot of new machinery to save one table.
     *
     *   forum_read_markers + forum_presence + the tables above
     *     They look like one shape ("user, thing, timestamp") but each points at
     *     a different parent: section, topic, post. Folding them together needs
     *     a polymorphic target_type/target_id, and a polymorphic column cannot
     *     carry a foreign key — deleting a topic would stop cascading to the
     *     rows about it. Trading referential integrity for a smaller table count
     *     is the opposite of the goal here.
     */

    /*
     * Tables already following the convention — listed so the verifier can
     * assert that every table in the database is accounted for by exactly one
     * of these four buckets.
     */
    'unchanged' => [
        'cp_api_idempotency',
        'cp_async_jobs',
        'cp_audit_logs',
        'cp_banned_ips',
        'cp_cron_jobs',
        'cp_cron_job_runs',
        'cp_entity_access_grants',
        'cp_entity_displays',
        'cp_field_definitions',
        'cp_locales',
        'cp_log_entries',
        'cp_mail_logs',
        'cp_mail_templates',
        'cp_migration_map',
        'cp_node_revisions',
        'cp_notifications',
        'cp_notification_digest_queue',
        'cp_password_history',
        'cp_path_alias_patterns',
        'cp_performance_backend_status',
        'cp_settings',
        'cp_system_telemetry_logs',
        'cp_terms',
        'cp_text_formats',
        'cp_user_sessions',
        'cp_vocabularies',
        'cp_webhook_subscriptions',
        'cp_whitepaper_documents',
        'cp_whitepaper_sections',
    ],

    /*
     * Owned by Doctrine and Symfony Messenger; renaming them buys nothing and
     * costs two library configurations.
     */
    'framework' => [
        'doctrine_migration_versions',
        'messenger_messages',
    ],

    /*
     * One collation for the whole database.
     *
     * The live dump carries three: utf8mb4_0900_ai_ci on the nine oldest tables
     * (users, nodes, assets, menus, menu_items, cp_settings, cp_cron_jobs,
     * cp_cron_job_runs, node_field_index), utf8mb4_unicode_ci on the other 56,
     * and utf8mb4_general_ci on doctrine_migration_versions. Joining a varchar
     * across that boundary raises "Illegal mix of collations" — it is a live
     * bug, not a tidiness problem.
     *
     * utf8mb4_unicode_ci wins because it is what 56 of the 66 tables already
     * use, so the conversion touches the fewest rows, and because it exists on
     * both MySQL 8 and the MariaDB 11.4 that cpalius.com runs.
     */
    'collation' => [
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_unicode_ci',
    ],
];
