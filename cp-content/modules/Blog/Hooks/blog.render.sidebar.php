<?php

declare(strict_types=1);

/**
 * Phase 7A flat-file hook for blog.render.sidebar (no DI; isolated Closure via HookManager).
 * Appends HTML on $context and returns the updated HookContext.
 *
 * Copy stays English here: flat-file hooks have no translator. Callers may
 * pass a translated 'flat_file_label' on the context when needed.
 */
return (static function (App\Core\Hook\HookContext $context): App\Core\Hook\HookContext {
    $siteName = (string) $context->get('site_name', 'CPalius CMF');
    $label = (string) $context->get('flat_file_label', 'Added by the Blog flat-file hook');

    return $context->appendHtml(sprintf(
        '<div class="cp-hook-blog-sidebar"><p>%s — %s.</p></div>',
        htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
    ));
})($context);
