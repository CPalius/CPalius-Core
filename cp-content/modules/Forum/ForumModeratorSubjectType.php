<?php

declare(strict_types=1);

namespace Modules\Forum;

enum ForumModeratorSubjectType: string
{
    case User = 'user';
    case Group = 'group';
}
