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
            'forum_link_previews',
            'forum_censor_words',
            'forum_moderation_logs',
            'forum_read_markers',
            'forum_drafts',
            'forum_topic_watches',
            'forum_poll_votes',
            'forum_poll_options',
            'forum_polls',
            'forum_post_attachments',
            'forum_post_likes',
            'forum_post_dislikes',
            'forum_post_reports',
            'forum_presence',
            'forum_topic_views',
            'forum_user_reputations',
            'forum_prefix_sections',
            'forum_posts',
            'forum_topics',
            'forum_topic_prefixes',
            'forum_bans',
            'forum_user_ranks',
            'forum_node_permissions',
            'forum_sections',
        ];
    }
}
