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
    /** The opening post carries the title and the question, so it gets longer. */
    public const DEFAULT_TOPIC_EDIT_TIMEOUT_MINUTES = 120;
    public const HOT_TOPIC_POST_THRESHOLD = 20;
}
