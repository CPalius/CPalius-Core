<?php

declare(strict_types=1);

namespace Modules\Forum\Notification;

/**
 * Short type / subject constants for forum inbox events (event_key = forum.{type}).
 * Replaces the deleted ForumNotification entity constants.
 */
final class ForumNotificationType
{
    public const REPLY = 'reply';
    public const THREAD_REPLY = 'thread_reply';
    public const QUOTE = 'quote';
    public const REACTION = 'reaction';
    public const DISLIKE = 'dislike';
    public const MENTION = 'mention';
    public const REPUTATION = 'reputation';
    public const WATCH = 'watch';

    public const CONTENT_POST = 'post';
    public const CONTENT_TOPIC = 'topic';
    public const CONTENT_USER = 'user';

    private function __construct()
    {
    }
}
