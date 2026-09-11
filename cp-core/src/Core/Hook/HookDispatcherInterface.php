<?php

declare(strict_types=1);

namespace App\Core\Hook;

/**
 * Narrow contract for triggering a hook point. Lets services depend on hook
 * dispatch without coupling to the concrete (isolated) HookManager.
 */
interface HookDispatcherInterface
{
    public function trigger(string $hookPoint, HookContext $context): HookContext;
}
