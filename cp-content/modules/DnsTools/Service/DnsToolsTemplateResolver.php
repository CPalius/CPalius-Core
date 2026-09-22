<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Twig\Environment;

final class DnsToolsTemplateResolver
{
    private const THEME_NAMESPACE = '@Theme/dnstools/';
    private const MODULE_NAMESPACE = '@DnsToolsModule/front/';
    private const THEME_LAYOUT = '@Theme/layout.html.twig';
    private const CORE_LAYOUT = 'base.html.twig';

    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(
        private readonly Environment $twig,
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

    private function exists(string $template): bool
    {
        try {
            return $this->twig->getLoader()->exists($template);
        } catch (\Throwable) {
            return false;
        }
    }
}
