<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Core\Localization\LocaleProvider;
use Twig\Environment;

/**
 * Theme override for public screens, same shape as ShowcaseTemplateResolver.
 */
final class MessagesTemplateResolver
{
    private const THEME_NAMESPACE = '@Theme/messages/';
    private const MODULE_NAMESPACE = '@MessagesModule/front/';
    private const THEME_LAYOUT = '@Theme/layout.html.twig';
    private const CORE_LAYOUT = 'base.html.twig';

    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(
        private readonly Environment $twig,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function resolve(string $name): string
    {
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        $themeTemplate = self::THEME_NAMESPACE.$name.'.html.twig';
        $moduleTemplate = self::MODULE_NAMESPACE.$name.'.html.twig';

        return $this->memo[$name] = $this->exists($themeTemplate) ? $themeTemplate : $moduleTemplate;
    }

    public function layout(): string
    {
        return $this->exists(self::THEME_LAYOUT) ? self::THEME_LAYOUT : self::CORE_LAYOUT;
    }

    public function locale(?string $requested = null): string
    {
        return $this->localeProvider->resolve($requested);
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
