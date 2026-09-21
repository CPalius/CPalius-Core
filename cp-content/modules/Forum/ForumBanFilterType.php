<?php

declare(strict_types=1);

namespace Modules\Forum;

enum ForumBanFilterType: string
{
    case Ip = 'ip';
    case Email = 'email';
    case Name = 'name';
}
