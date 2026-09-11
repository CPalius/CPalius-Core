<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Security\Http\CspNonceProvider;
use App\Core\Security\Http\SecurityHeaderPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the hardening header set. Existing headers are never overwritten so a
 * controller (or the profiler) can opt out of a specific policy deliberately.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const SKIP_PREFIXES = ['/_wdt', '/_profiler'];

    public function __construct(
        private readonly SecurityHeaderPolicy $policy,
        private readonly CspNonceProvider $nonceProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // The nonce must exist before templates render, not when the response leaves.
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            if ($this->policy->enabled() && $this->policy->nonceRequired()) {
                $this->nonceProvider->nonceFor($event->getRequest());
            }
        } catch (\Throwable) {
            // A settings read failure must not take the site down.
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return;
            }
        }

        try {
            if (!$this->policy->enabled()) {
                return;
            }

            $response = $event->getResponse();
            $nonce = (string) $request->attributes->get(CspNonceProvider::ATTRIBUTE, '');

            // A download still gets the anti-sniffing headers. Dropping the whole
            // set here used to hand an attachment whose bytes a user supplied
            // (an avatar, an import file) to the browser with nothing stopping it
            // from re-typing the body as HTML and running script on our origin.
            // Only the policy headers that are meaningless on an attachment body
            // — CSP and the framing rules — are skipped.
            $skip = $this->isStreamedDownload($response)
                ? ['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Frame-Options']
                : [];

            foreach ($this->policy->headers($request, $nonce) as $name => $value) {
                if (\in_array($name, $skip, true)) {
                    continue;
                }

                if (!$response->headers->has($name)) {
                    $response->headers->set($name, $value);
                }
            }
        } catch (\Throwable) {
            // Same rule: hardening degrades, it never 500s.
        }
    }

    /**
     * File downloads get nosniff from the web server; CSP on an attachment body is noise.
     */
    private function isStreamedDownload(Response $response): bool
    {
        $disposition = (string) $response->headers->get('Content-Disposition', '');

        return str_starts_with($disposition, 'attachment');
    }
}
