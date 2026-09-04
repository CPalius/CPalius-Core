<?php

declare(strict_types=1);

/**
 * Phase 7A flat-file hook for blog.render.sidebar (no DI; isolated Closure via HookManager).
 * Appends HTML on $context and returns the updated HookContext.
 */
return (static function (App\Core\Hook\HookContext $context): App\Core\Hook\HookContext {
    $siteName = (string) $context->get('site_name', 'CPalius CMF');

    return $context->appendHtml(sprintf(
        '<div class="cp-hook-blog-sidebar"><p>%s — Blog modülünden flat-file kanca ile eklendi.</p></div>',
        htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ));
})($context);
