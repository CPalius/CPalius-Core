<?php

declare(strict_types=1);

namespace Modules\Forum;

enum ForumPermissionRoleScope: string
{
    case Content = 'content';
    case Moderate = 'moderate';
}
