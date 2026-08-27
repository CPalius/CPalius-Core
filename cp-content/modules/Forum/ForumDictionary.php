<?php

declare(strict_types=1);

namespace Modules\Forum;

final class ForumDictionary
{
    public const TOPIC_MODE_NORMAL = 0;
    public const TOPIC_MODE_PRIVATE = 1;

    public const TOPIC_STATE_OPEN = 0;
    public const TOPIC_STATE_LOCKED = 1;

    public const DEFAULT_TOPICS_PER_PAGE = 30;
    public const DEFAULT_POSTS_PER_PAGE = 15;
    public const DEFAULT_EDIT_TIMEOUT_MINUTES = 15;
    public const HOT_TOPIC_POST_THRESHOLD = 20;
}
