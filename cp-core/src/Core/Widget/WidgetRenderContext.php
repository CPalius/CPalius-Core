<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * What a widget may know about the page it is drawn on.
 *
 * `page` is a plain word the theme chooses ("list", "post", "search"…); widgets that care (an author card
 * on a post page) read it, and every widget can be limited to certain pages in Studio.
 * `module` is the owner of the area being drawn and `theme` the active theme's directory name, so one type can
 * look different in the blog sidebar than in the docs.
 * `subject` is the main thing on the page when there is one (the Node being read), null otherwise.
 */
final readonly class WidgetRenderContext
{
    public function __construct(
        public string $locale,
        public string $page = 'other',
        public ?object $subject = null,
        public string $module = '',
        public string $theme = '',
    ) {
    }
}
