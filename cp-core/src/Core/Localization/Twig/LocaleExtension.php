<?php

declare(strict_types=1);

namespace App\Core\Localization\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Front Twig API: cp_locales, cp_locale_switcher, cp_hreflang_links. Work lives in LocaleRuntime (lazy).
 * Switcher returns '' on a single-locale site; HTML is marked is_safe (escaping is in the templates).
 */
final class LocaleExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_locales', [LocaleRuntime::class, 'locales']),
            new TwigFunction('cp_locale', [LocaleRuntime::class, 'currentLocale']),
            new TwigFunction('cp_locale_url', [LocaleRuntime::class, 'urlFor']),
            new TwigFunction('cp_locale_links', [LocaleRuntime::class, 'links']),
            new TwigFunction('cp_locale_switcher', [LocaleRuntime::class, 'switcher'], ['is_safe' => ['html']]),
            new TwigFunction('cp_hreflang_links', [LocaleRuntime::class, 'hreflangLinks'], ['is_safe' => ['html']]),
        ];
    }
}
