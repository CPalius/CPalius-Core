<?php

declare(strict_types=1);

namespace Modules\Forum\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Board statistics card above the footer, on forum pages only (theme.footer.before in the theme layout).
 */
final class ThemeFooterStatsHook
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RequestStack $requests,
    ) {
    }

    #[CpHook('theme.footer.before', priority: 50)]
    public function onFooterBefore(HookContext $context): HookContext
    {
        $route = (string) $this->requests->getCurrentRequest()?->attributes->get('_route', '');
        // Other modules' pages (blog, portal, pages, home) must not carry forum statistics.
        if (!str_starts_with($route, 'forum_')) {
            return $context;
        }

        try {
            $html = $this->twig->render('@Theme/forum/partials/_stats_bar.html.twig');
        } catch (\Throwable) {
            // A page without the card beats no page.
            return $context;
        }

        // Inside .container so the card lines up with the page column instead of spanning the viewport.
        return $context->appendHtml('<div class="container forum-stats-wrap">'.$html.'</div>');
    }
}
