<?php

declare(strict_types=1);

namespace Modules\Forum;

/**
 * Ternary ACL cell: missing row = Inherit.
 * Deny wins when groups disagree.
 */
enum ForumAclEffect: string
{
    case Inherit = 'inherit';
    case Allow = 'allow';
    case Deny = 'deny';

    public function isAllow(): bool
    {
        return $this === self::Allow;
    }

    public function isDeny(): bool
    {
        return $this === self::Deny;
    }

    public function isInherit(): bool
    {
        return $this === self::Inherit;
    }
}
