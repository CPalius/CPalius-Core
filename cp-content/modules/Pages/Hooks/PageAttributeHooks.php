<?php

declare(strict_types=1);

namespace Modules\Pages\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;

/**
 * Extension point under the page body so other modules can append HTML.
 */
final class PageAttributeHooks
{
    #[CpHook('page.render.content', priority: 50)]
    public function onContentRender(HookContext $context): HookContext
    {
        return $context;
    }
}
