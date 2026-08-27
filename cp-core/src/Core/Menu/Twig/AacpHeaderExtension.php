<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AacpHeaderExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_current_user_avatar_url', [AacpHeaderRuntime::class, 'currentUserAvatarUrl']),
        ];
    }
}
