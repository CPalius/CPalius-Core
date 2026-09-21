<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Pure visitor/spoiler rules. The request-aware services call this so the
 * decisions can be unit-tested without a container.
 */
final class ForumGuestPolicy
{
    /**
     * Human guests and generic bots are restricted; signed-in members and
     * search-engine spiders are not.
     */
    public static function hideFromVisitor(bool $settingOn, string $kind): bool
    {
        if (!$settingOn) {
            return false;
        }

        return $kind === ForumVisitorKind::GUEST || $kind === ForumVisitorKind::BOT;
    }

    /**
     * A spoiler opens for staff (site admin / forum moderator), for search
     * engines that need the inner HTML to index, for the post's author, and
     * for a member who liked that post or replied in the topic. Visitors
     * never unlock it.
     */
    public static function spoilerUnlocked(
        bool $isStaff,
        string $kind,
        bool $isAuthor,
        bool $likedThisPost,
        bool $repliedInTopic,
    ): bool {
        if ($isStaff) {
            return true;
        }

        if ($kind === ForumVisitorKind::SPIDER) {
            return true;
        }

        if ($kind !== ForumVisitorKind::MEMBER) {
            return false;
        }

        return $isAuthor || $likedThisPost || $repliedInTopic;
    }
}
