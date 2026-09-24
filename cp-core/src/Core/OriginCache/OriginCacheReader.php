<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Localization\LocaleProvider;
use App\Core\Performance\PerformanceBackendRegistry;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves a disk snapshot when Apache did not (query string or missing rewrite).
 */
final class OriginCacheReader implements EventSubscriberInterface
{
    public function __construct(
        private readonly OriginCacheStore $store,
        private readonly OriginCachePolicy $policy,
        private readonly PerformanceBackendRegistry $registry,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 24],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $status = $this->registry->getStatus('cpalius');
        if ($status === null || !$status->isEnabled() || !$this->store->isEnabledOnDisk()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->policy->isCacheableRequest($request)) {
            return;
        }

        $excludes = $this->policy->parseExcludes((string) $this->settingsRegistry->get(
            'performance.cpalius.excludes',
            OriginCachePolicy::DEFAULT_EXCLUDES,
        ));
        if ($this->policy->isExcluded($request->getPathInfo(), $excludes)) {
            return;
        }

        $ttl = $this->policy->normalizeTtl($this->settingsRegistry->get(
            'performance.cpalius.ttl',
            OriginCachePolicy::DEFAULT_TTL,
        ));
        $ctx = OriginCacheVaryContext::fromRequest($request, $this->localeProvider, authenticated: false);
        $html = $this->store->getHtml(
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            $ttl,
            $ctx,
        );
        if ($html === null) {
            return;
        }

        $response = new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-CPalius-Cache' => 'HIT',
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language, Cookie',
        ]);
        $event->setResponse($response);
    }
}
