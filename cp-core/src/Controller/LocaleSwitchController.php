<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\EventListener\LocaleListener;
use App\Core\Localization\LocaleProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Front-end locale switch for unprefixed routes; sets cookie and redirects to referer/home.
 * AACP equivalent: AACPLocaleController (/aacp/locale/{locale}).
 */
final class LocaleSwitchController extends AbstractController
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly LocaleListener $localeListener,
    ) {
    }

    #[Route(
        '/locale/{locale}',
        name: 'locale_switch',
        methods: ['GET'],
        requirements: ['locale' => '%cpalius.locales_pattern%'],
    )]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $resolved = $this->localeProvider->isSupported($locale)
            ? $locale
            : $this->localeProvider->getDefaultCode();

        $response = $this->buildRedirect($request, $resolved);
        $response->headers->setCookie($this->localeListener->buildCookie($resolved, $request));

        if ($request->hasPreviousSession()) {
            $request->getSession()->set(LocaleListener::SESSION_KEY, $resolved);
        }

        return $response;
    }

    private function buildRedirect(Request $request, string $locale): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        if (\is_string($referer) && $referer !== '') {
            $sanitized = $this->sameHostReturnUrl($referer, $request);

            if ($sanitized !== null) {
                return new RedirectResponse($sanitized);
            }
        }

        return $this->redirectToRoute('theme_cpalius_website_home', ['_locale' => $locale]);
    }

    /**
     * Open-redirect guard; strip stale ?_locale= so cookie choice wins.
     */
    private function sameHostReturnUrl(string $referer, Request $request): ?string
    {
        $parts = parse_url($referer);

        if (!\is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (\is_string($host) && $host !== $request->getHost()) {
            return null;
        }

        $query = [];

        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            unset($query['_locale']);
        }

        $path = \is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        $qs = http_build_query($query);
        $url = $path.($qs !== '' ? '?'.$qs : '');

        if (isset($parts['fragment']) && \is_string($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
