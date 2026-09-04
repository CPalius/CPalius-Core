<?php

declare(strict_types=1);

namespace Modules\Forum;

/**
 * CPalius Forum Engine discussion_state.
 */
enum ForumDiscussionState: string
{
    case Visible = 'visible';
    case Moderated = 'moderated';
    case Deleted = 'deleted';

    public function isPublic(): bool
    {
        return $this === self::Visible;
    }
}
