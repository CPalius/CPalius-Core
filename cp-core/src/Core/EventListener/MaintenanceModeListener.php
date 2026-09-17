<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Settings\SettingsRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Serves the maintenance page to visitors while core.maintenance_mode is on.
 *
 * The switch existed as a setting long before anything read it. This is the
 * reader: a 503 with a Retry-After, not a 200, so search engines treat the
 * downtime as temporary and keep the indexed pages they already have.
 *
 * Staff keep the whole site: an operator who cannot reach the panel that turns
 * maintenance off has locked themselves out of their own installation, and the
 * login routes stay open for the same reason.
 */
final class MaintenanceModeListener implements EventSubscriberInterface
{
    /** Turning it off again must never require editing the database by hand. */
    private const ALWAYS_OPEN_PREFIXES = [
        '/aacp',
        '/admin',
        '/login',
        '/logout',
        '/hesap/giris',
        '/cron/execute',
        '/uploads/',
        '/themes/',
        '/build/',
        '/assets/',
        '/_wdt',
        '/_profiler',
    ];

    /** A 503 with no hint tells a crawler nothing; an hour is the honest default. */
    private const RETRY_AFTER_SECONDS = 3600;

    private const BYPASS_CAPABILITY = 'system.aacp.access';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) so the staff check has a token to read, and
        // before the controller runs so no page is built just to be discarded.
        return [KernelEvents::REQUEST => [['onKernelRequest', 6]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        foreach (self::ALWAYS_OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Settings live in the database, which is exactly what tends to be
        // unavailable mid-deploy. Failing open keeps a reachable site up
        // instead of hiding it behind a maintenance page nobody switched on.
        try {
            $enabled = (bool) $this->settingsRegistry->get('core.maintenance_mode', false);
        } catch (\Throwable) {
            return;
        }

        if (!$enabled || $this->isStaff($event)) {
            return;
        }

        $event->setResponse($this->buildResponse());
    }

    /**
     * Law 6.4: isGranted() opens a session, and this runs on every anonymous
     * hit. A visitor with no session cookie cannot be signed in, so the cheap
     * check answers the question without costing the site its sessionless
     * anonymous traffic.
     */
    private function isStaff(RequestEvent $event): bool
    {
        if (!$event->getRequest()->hasPreviousSession()) {
            return false;
        }

        try {
            return $this->security->isGranted(self::BYPASS_CAPABILITY);
        } catch (\Throwable) {
            // A path outside every firewall has no token to check. Answering
            // "not staff" costs an operator nothing: /aacp and the login routes
            // are open regardless, so the way back in is never through here.
            return false;
        }
    }

    private function buildResponse(): Response
    {
        $html = $this->twig->render('maintenance.html.twig', [
            'siteName' => trim((string) $this->settingsRegistry->get('core.site_name', '')),
            'title' => trim((string) $this->settingsRegistry->get('core.maintenance_title', '')),
            'message' => trim((string) $this->settingsRegistry->get('core.maintenance_message', '')),
        ]);

        $response = new Response($html, Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        // Reverse proxies and the origin cache must not keep serving this page
        // to everyone once maintenance is switched back off.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
