<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Settings\SettingsRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Closes the public website for installations that are used as a back office
 * (ERP, CRM) rather than as a site.
 *
 *  - open        : nothing changes.
 *  - login_only  : visitors who are not signed in see the sign-in page and nothing
 *                  else. Signed-in members use the site and every module as usual.
 *  - admin_only  : there is no website. Only AACP, Studio and the module admin
 *                  screens answer; every public address is a 404 for everyone
 *                  except staff, who are sent to the panel from the front door.
 *
 * Unlike maintenance this is a standing configuration, not an outage, so it
 * answers with a redirect or a 404 rather than a 503 — and it asks crawlers to
 * stay away either way.
 */
final class SiteAccessListener implements EventSubscriberInterface
{
    public const KEY = 'core.site_access';
    public const OPEN = 'open';
    public const LOGIN_ONLY = 'login_only';
    public const ADMIN_ONLY = 'admin_only';
    public const MODES = [self::OPEN, self::LOGIN_ONLY, self::ADMIN_ONLY];

    /**
     * Reachable in every mode, so the way back in can never be closed:
     * the panels, the sign-in/out routes, password recovery, machine endpoints
     * (API key / signed secret) and the static files the login page itself needs.
     */
    private const ALWAYS_OPEN_PREFIXES = [
        '/aacp',
        '/admin',
        '/login',
        '/logout',
        '/hesap/giris',
        '/hesap/sifremi-unuttum',
        '/hesap/sifre-sifirla/',
        '/hesap/iki-adimli',
        '/api',
        '/cron/execute',
        '/robots.txt',
        '/uploads/',
        '/themes/',
        '/build/',
        '/assets/',
        '/_wdt',
        '/_profiler',
        '/_fragment',
    ];

    private const STAFF_CAPABILITY = 'system.aacp.access';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) so the token is readable, after maintenance (6)
        // so a real outage still wins, before the controller runs.
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 5]],
            KernelEvents::RESPONSE => [['onKernelResponse', -64]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $mode = $this->mode();
        if ($mode === self::OPEN) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        foreach (self::ALWAYS_OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Law 6.4: isGranted() opens a session. Without a session cookie nobody
        // can be signed in, so the anonymous case costs nothing.
        $signedIn = $request->hasPreviousSession() && $this->security->getUser() !== null;
        $login = fn (): Response => $this->noindex(new RedirectResponse($this->urls->generate('admin_login')));

        if ($mode === self::LOGIN_ONLY) {
            // Members use the site and every module. Self-registration is part
            // of "the site", so it is closed with the rest: an internal system
            // does not take sign-ups from the street.
            if (!$signedIn) {
                $event->setResponse($login());
            }

            return;
        }

        // admin_only
        if ($signedIn && $this->isStaff()) {
            if ($this->isFrontDoor($path)) {
                $event->setResponse(new RedirectResponse($this->urls->generate('admin_dashboard')));
            }

            return;
        }

        $event->setResponse(
            !$signedIn && $this->isFrontDoor($path)
                ? $login()
                : $this->noindex(new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8'])),
        );
    }

    /** Everything this mode serves tells search engines not to index it. */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->mode() === self::OPEN) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    private function mode(): string
    {
        // Settings live in the database, which is what is unavailable during a
        // deploy. Fail open, as maintenance does: a reachable site stays up.
        try {
            $mode = (string) $this->settings->get(self::KEY, self::OPEN);
        } catch (\Throwable) {
            return self::OPEN;
        }

        return \in_array($mode, self::MODES, true) ? $mode : self::OPEN;
    }

    private function isStaff(): bool
    {
        try {
            return $this->security->isGranted(self::STAFF_CAPABILITY);
        } catch (\Throwable) {
            return false;
        }
    }

    /** "/" and "/{locale}" — the addresses a person types, not a deep link. */
    private function isFrontDoor(string $path): bool
    {
        return $path === '/' || preg_match('~^/[a-z]{2}(?:[-_][A-Za-z]{2,4})?/?$~', $path) === 1;
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
