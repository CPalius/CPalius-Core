<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Mybb;

/**
 * MyBB's three-state "visible" column, as a state the forum module understands.
 *
 * Shared by threads and posts because getting it wrong in one of them and right
 * in the other would produce a board where a hidden thread has visible replies.
 */
final class MybbVisibility
{
    public static function toState(string $visible): string
    {
        return match (trim($visible)) {
            '1' => 'visible',
            '-1' => 'deleted',
            // 0, and anything a version or add-on introduced that we do not
            // recognise: held for a moderator, which is the safe direction.
            default => 'moderated',
        };
    }
}
