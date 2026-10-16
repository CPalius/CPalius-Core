<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

use App\Core\Widget\WidgetAreaProviderInterface;

/**
 * The forum_sidebar area is gone: the latest/popular cards under the forum pages are now managed in
 * Studio → Forum → Settings → Topic cards. The widget types stay available for other areas.
 */
final class ForumWidgetAreaProvider implements WidgetAreaProviderInterface
{
    public function areas(): array
    {
        return [];
    }
}
