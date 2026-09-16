<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use Twig\Environment;

/**
 * Picks the template for a front-end screen, preferring a theme override.
 *
 * A module installed from a ZIP has to render before anyone edits the theme, so
 * Showcase ships its own front templates. But Law 7 keeps themes in charge of
 * presentation, so a theme that provides `showcase/<name>.html.twig` wins without
 * touching the module.
 *
 * The same trick picks the page chrome: the module layout extends whatever this
 * returns, so it sits inside the site's real header and footer when a theme
 * layout exists, and still renders standalone when it does not (recovery mode,
 * a half-installed theme, a fresh install).
 */
final class ShowcaseTemplateResolver
{
    private const THEME_NAMESPACE = '@Theme/showcase/';
    private const MODULE_NAMESPACE = '@ShowcaseModule/front/';

    private const THEME_LAYOUT = '@Theme/layout.html.twig';
    private const CORE_LAYOUT = 'base.html.twig';

    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param string $name bare template name, e.g. "index" or "partials/card"
     */
    public function resolve(string $name): string
    {
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        $themeTemplate = self::THEME_NAMESPACE.$name.'.html.twig';
        $moduleTemplate = self::MODULE_NAMESPACE.$name.'.html.twig';

        return $this->memo[$name] = $this->exists($themeTemplate) ? $themeTemplate : $moduleTemplate;
    }

    /**
     * Parent layout for the module's own front templates.
     */
    public function layout(): string
    {
        return $this->exists(self::THEME_LAYOUT) ? self::THEME_LAYOUT : self::CORE_LAYOUT;
    }

    private function exists(string $template): bool
    {
        try {
            return $this->twig->getLoader()->exists($template);
        } catch (\Throwable) {
            return false;
        }
    }
}
