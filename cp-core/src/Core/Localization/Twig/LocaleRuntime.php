<?php

declare(strict_types=1);

namespace App\Core\Localization\Twig;

use App\Core\Localization\LocaleDefinition;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\Service\LocaleSwitchService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * LocaleExtension runtime. No request (CLI/warmup) returns empty/safe values — never throw.
 */
final class LocaleRuntime implements RuntimeExtensionInterface
{
    public const STYLE_BUTTONS = 'buttons';
    public const STYLE_DROPDOWN = 'dropdown';

    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly LocaleSwitchService $localeSwitchService,
        private readonly RequestStack $requestStack,
        private readonly Environment $twig,
    ) {
    }

    /**
     * Active locales for themes that build their own switcher.
     *
     * @return list<LocaleDefinition>
     */
    public function locales(): array
    {
        return $this->localeProvider->getLocales();
    }

    /**
     * Request locale, or the site default when there is no request.
     */
    public function currentLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request !== null
            ? $this->localeProvider->resolve($request->getLocale())
            : $this->localeProvider->getDefaultCode();
    }

    /**
     * Counterpart URL in $targetLocale, or that locale's homepage — never a 404.
     */
    public function urlFor(string $targetLocale): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return $this->localeSwitchService->homeUrl($targetLocale);
        }

        return $this->localeSwitchService->urlFor($request, $targetLocale);
    }

    /**
     * Raw switcher rows: code, URL, native name, current/default flags.
     *
     * @return list<array{code: string, url: string, isCurrent: bool, isDefault: bool, nativeName: string}>
     */
    public function links(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return [];
        }

        return $this->localeSwitchService->alternateLinks($request);
    }

    /**
     * Ready-made switcher HTML.
     *
     * @param string $style self::STYLE_BUTTONS | self::STYLE_DROPDOWN
     */
    public function switcher(string $style = self::STYLE_BUTTONS): string
    {
        $links = $this->links();

        // No markup for a single-locale site (or when there is no request).
        if (\count($links) < 2) {
            return '';
        }

        $current = $this->currentLocale();

        return $this->twig->render('localization/switcher.html.twig', [
            'style' => $style === self::STYLE_DROPDOWN ? self::STYLE_DROPDOWN : self::STYLE_BUTTONS,
            'current' => $current,
            'currentLink' => $this->findCurrentLink($links, $current),
            'links' => $links,
        ]);
    }

    /**
     * rel="alternate" hreflang tags plus x-default pointing at the default locale's counterpart URL.
     */
    public function hreflangLinks(): string
    {
        $links = $this->links();

        if ($links === []) {
            return '';
        }

        $defaultCode = $this->localeProvider->getDefaultCode();
        $defaultLink = null;

        foreach ($links as $link) {
            if ($link['code'] === $defaultCode) {
                $defaultLink = $link;
                break;
            }
        }

        return $this->twig->render('localization/hreflang.html.twig', [
            'links' => $links,
            // x-default: counterpart in the default locale, else that locale's homepage.
            'defaultUrl' => $defaultLink['url'] ?? $this->localeSwitchService->homeUrl($defaultCode),
        ]);
    }

    /**
     * @param list<array{code: string, url: string, isCurrent: bool, isDefault: bool, nativeName: string}> $links
     *
     * @return array{code: string, url: string, isCurrent: bool, isDefault: bool, nativeName: string}
     */
    private function findCurrentLink(array $links, string $current): array
    {
        foreach ($links as $link) {
            if ($link['code'] === $current) {
                return $link;
            }
        }

        // resolve() already clamps to the active list; first link keeps the dropdown trigger non-empty.
        return $links[0];
    }
}
