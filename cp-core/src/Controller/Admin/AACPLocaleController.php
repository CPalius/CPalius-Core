<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\EventListener\LocaleListener;
use App\Core\Localization\LocaleProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Switches AACP UI locale via cp_locale cookie (Law 6.4), shared with the front end.
 * Route uses compile-time %cpalius.locales_pattern%; unsupported codes are ignored.
 */
final class AACPLocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly LocaleListener $localeListener,
    ) {
    }

    #[Route(
        '/aacp/locale/{locale}',
        name: 'aacp_locale_switch',
        methods: ['GET'],
        requirements: ['locale' => '%cpalius.locales_pattern%'],
    )]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $response = $this->buildRedirect($request);

        if ($this->localeProvider->isSupported($locale)) {
            $response->headers->setCookie($this->localeListener->buildCookie($locale, $request));

            // Sync session locale with cookie when a session already exists.
            if ($request->hasPreviousSession()) {
                $request->getSession()->set(LocaleListener::SESSION_KEY, $locale);
            }
        }

        return $response;
    }

    private function buildRedirect(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        // Open-redirect guard: same host only.
        if (\is_string($referer) && $referer !== '') {
            $host = parse_url($referer, \PHP_URL_HOST);

            if ($host === null || $host === false || $host === $request->getHost()) {
                return new RedirectResponse($referer);
            }
        }

        return $this->redirectToRoute('aacp_dashboard');
    }
}
