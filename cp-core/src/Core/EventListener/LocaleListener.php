<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Localization\LocaleProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolve interface locale without starting a session (Law 6.4). Cookie is written only on an explicit choice.
 * Order: URL prefix → ?_locale= → cookie → Accept-Language → default. AACP uses the same cookie (no URL prefix).
 */
final class LocaleListener implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'cp_locale';
    public const COOKIE_LIFETIME = 31536000; // 1 year
    public const AACP_PATH_PREFIX = '/aacp';

    /**
     * @deprecated cookie-based now; read only on an already-open session for backward compatibility
     */
    public const SESSION_KEY = '_aacp_locale';

    /**
     * Request flag so onKernelResponse can set the cookie. Stateless: no instance field (worker reuse).
     */
    private const WRITE_COOKIE_ATTRIBUTE = '_cp_locale_write';

    private const URL_LOCALE_PATTERN = '#^/(?P<locale>[a-z]{2,5})(?:/|$)#';

    public function __construct(
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before RouterListener (32) so an unsupported prefix becomes the default, not 404.
            KernelEvents::REQUEST => [['onKernelRequest', 40]],
            KernelEvents::RESPONSE => [['onKernelResponse', 0]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        [$locale, $isExplicitChoice] = $this->resolveLocale($request);

        $request->setLocale($locale);
        $request->attributes->set('_cp_locale', $locale);

        // Cookie only on an explicit choice that differs from the current cookie (avoid breaking HTTP cache).
        if ($isExplicitChoice && $request->cookies->get(self::COOKIE_NAME) !== $locale) {
            $request->attributes->set(self::WRITE_COOKIE_ATTRIBUTE, $locale);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $locale = $event->getRequest()->attributes->get(self::WRITE_COOKIE_ATTRIBUTE);

        if (!\is_string($locale) || $locale === '') {
            return;
        }

        $event->getResponse()->headers->setCookie($this->buildCookie($locale, $event->getRequest()));
    }

    /**
     * Shared cookie factory for explicit locale-switch endpoints (same lifetime/path/samesite).
     */
    public function buildCookie(string $locale, Request $request): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($locale)
            ->withExpires(time() + self::COOKIE_LIFETIME)
            ->withPath('/')
            ->withSecure($request->isSecure())
            // Not httpOnly: theme JS may read the locale. Cookie holds no identity, so XSS risk is low.
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /**
     * @return array{string, bool} [resolved code, whether the user chose it explicitly]
     */
    private function resolveLocale(Request $request): array
    {
        $path = $request->getPathInfo();

        // 1) URL prefix — /tr/blog, /en/forum. Not an explicit choice: writing
        // cp_locale here Set-Cookie's every first anonymous hit and origin cache never stores.
        if (preg_match(self::URL_LOCALE_PATTERN, $path, $matches) === 1
            && $this->localeProvider->isSupported($matches['locale'])
        ) {
            return [$matches['locale'], false];
        }

        // 2) Query _locale on unprefixed routes. Counts as an explicit choice (writes cookie).
        $queryLocale = $request->query->get('_locale');

        if (\is_string($queryLocale) && $this->localeProvider->isSupported($queryLocale)) {
            return [$queryLocale, true];
        }

        // 2b) AACP has no prefix. Honor a legacy session key only if a session is already open.
        if (str_starts_with($path, self::AACP_PATH_PREFIX) && $request->hasPreviousSession()) {
            $sessionLocale = $request->getSession()->get(self::SESSION_KEY);

            if (\is_string($sessionLocale) && $this->localeProvider->isSupported($sessionLocale)) {
                return [$sessionLocale, false];
            }
        }

        // 3) Cookie — previous explicit choice.
        $cookieLocale = $request->cookies->get(self::COOKIE_NAME);

        if (\is_string($cookieLocale) && $this->localeProvider->isSupported($cookieLocale)) {
            return [$cookieLocale, false];
        }

        // 4) Accept-Language intersected with supported codes.
        $preferred = $request->getPreferredLanguage($this->localeProvider->getCodes());

        if (\is_string($preferred) && $this->localeProvider->isSupported($preferred)) {
            return [$preferred, false];
        }

        // 5) Site default.
        return [$this->localeProvider->getDefaultCode(), false];
    }
}
