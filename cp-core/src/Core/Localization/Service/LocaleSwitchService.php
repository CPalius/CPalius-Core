<?php

declare(strict_types=1);

namespace App\Core\Localization\Service;

use App\Core\EventListener\LocaleListener;
use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Counterpart URL for the current page in another locale.
 * When a translation sibling exists, slug params are rewritten.
 * When it does not, the same route/slug is kept so the target page can
 * show a soft "unavailable in this language" state — never a hard 404 jump to home.
 * AACP has no URL prefix; panel locale uses the cp_locale cookie.
 */
final class LocaleSwitchService
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function urlFor(Request $request, string $targetLocale): string
    {
        $targetLocale = $this->localeProvider->resolve($targetLocale);

        if (str_starts_with($request->getPathInfo(), LocaleListener::AACP_PATH_PREFIX)) {
            return $this->urlGenerator->generate('aacp_locale_switch', ['locale' => $targetLocale]);
        }

        $route = $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');

        if (!\is_string($route) || $route === '' || !\is_array($params)) {
            return $this->switcherUrl($targetLocale);
        }

        try {
            $params = $this->withTranslatedParams($request, $params, $targetLocale);
            $params['_locale'] = $targetLocale;
            $url = $this->urlGenerator->generate($route, $params);
        } catch (RoutingException) {
            return $this->homeUrl($targetLocale);
        }

        // Unprefixed routes put _locale in the query string; that does not switch the page — use /locale/{code}.
        if ($this->pathCarriesLocale($url, $targetLocale)) {
            return $url;
        }

        return $this->switcherUrl($targetLocale);
    }

    private function switcherUrl(string $targetLocale): string
    {
        try {
            return $this->urlGenerator->generate('locale_switch', ['locale' => $targetLocale]);
        } catch (RoutingException) {
            return $this->homeUrl($targetLocale);
        }
    }

    private function pathCarriesLocale(string $url, string $locale): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);

        if (!\is_string($path) || $path === '') {
            return false;
        }

        return $path === '/'.$locale || str_starts_with($path, '/'.$locale.'/');
    }

    /**
     * @return list<array{code: string, url: string, isCurrent: bool, isDefault: bool, nativeName: string}>
     */
    public function alternateLinks(Request $request): array
    {
        $current = $request->getLocale();
        $links = [];

        foreach ($this->localeProvider->getLocales() as $locale) {
            $links[] = [
                'code' => $locale->code,
                'url' => $this->urlFor($request, $locale->code),
                'isCurrent' => $locale->code === $current,
                'isDefault' => $locale->isDefault,
                'nativeName' => $locale->nativeName,
            ];
        }

        return $links;
    }

    public function homeUrl(string $locale): string
    {
        try {
            return $this->urlGenerator->generate('theme_cpalius_website_home', [
                '_locale' => $this->localeProvider->resolve($locale),
            ]);
        } catch (RoutingException) {
            return '/'.$this->localeProvider->resolve($locale);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function withTranslatedParams(Request $request, array $params, string $targetLocale): array
    {
        foreach ($request->attributes->all() as $value) {
            if (!$value instanceof TranslatableInterface) {
                continue;
            }

            $group = $this->translationGroupResolver->findGroup($value);
            $translation = $group[$targetLocale] ?? null;

            // No sibling: keep current slug so blog/forum controllers can render
            // a soft unavailable page instead of bouncing to the homepage.
            if (!$translation instanceof TranslatableInterface) {
                continue;
            }

            if (method_exists($translation, 'getSlug')) {
                $slug = $translation->getSlug();

                if (\is_string($slug) && $slug !== '') {
                    if (isset($params['slug'])) {
                        $params['slug'] = $slug;
                    }
                    if (isset($params['sectionSlug'])) {
                        $params['sectionSlug'] = $slug;
                    }
                }
            }
        }

        return $params;
    }
}
