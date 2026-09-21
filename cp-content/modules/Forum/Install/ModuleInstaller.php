<?php

declare(strict_types=1);

namespace Modules\Forum\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Forum schema originated in cp-core/migrations/. New SQL goes in Resources/migrations/.
 * Uninstall(--purge) drops forum_* tables; it does not roll back core Doctrine versions.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'forum';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_forum_topics_posted',
            'cp_forum_announcements',
            'cp_forum_warnings',
            'cp_forum_ban_filters',
            'cp_forum_user_blocks',
            'cp_forum_moderator_cache',
            'cp_forum_permission_role_grants',
            'cp_forum_permission_roles',
            'cp_forum_user_permissions',
            'cp_forum_moderators',
            'cp_forum_topic_view_buffer',
            'cp_forum_user_stats',
            'cp_forum_board_stats',
            'cp_forum_link_previews',
            'cp_forum_censor_words',
            'cp_forum_moderation_logs',
            'cp_forum_read_markers',
            'cp_forum_drafts',
            'cp_forum_topic_user_state',
            'cp_forum_poll_votes',
            'cp_forum_poll_options',
            'cp_forum_polls',
            'cp_forum_post_attachments',
            'cp_forum_post_votes',
            'cp_forum_post_reports',
            'cp_forum_presence',
            'cp_forum_user_reputations',
            'cp_forum_prefix_sections',
            'cp_forum_posts',
            'cp_forum_topics',
            'cp_forum_topic_prefixes',
            'cp_forum_bans',
            'cp_forum_user_ranks',
            'cp_forum_node_permissions',
            'cp_forum_sections',
        ];
    }
}
