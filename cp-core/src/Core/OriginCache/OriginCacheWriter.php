<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use App\Repository\PerformanceBackendStatusRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes anonymous HTML snapshots after the response is sent.
 */
final class OriginCacheWriter implements EventSubscriberInterface
{
    public function __construct(
        private readonly OriginCacheStore $store,
        private readonly OriginCachePolicy $policy,
        private readonly OriginHtmlProcessor $processor,
        private readonly PerformanceBackendStatusRepository $statusRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly CacheTagCollector $tagCollector,
        private readonly CacheTagIndex $tagIndex,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After SessionListener (-1000) so Set-Cookie is visible for diagnostics.
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
            KernelEvents::TERMINATE => ['onKernelTerminate', -64],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isOriginEnabled()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->get('X-CPalius-Cache') === 'HIT') {
            return;
        }

        $reason = $this->bypassReason($event->getRequest(), $response);
        $response->headers->set('X-CPalius-Cache', $reason === null ? 'MISS' : 'BYPASS');
        if ($reason !== null) {
            $response->headers->set('X-CPalius-Cache-Reason', $reason);
        }

        // T2.3: exposed even on a MISS so reverse proxies that DO sit in front (Varnish
        // xkey, Fastly Surrogate-Key) can build their own purge index off the same tags,
        // independent of CPalius's own filesystem snapshot.
        $this->tagCollector->addTags($this->settingsRegistry->peekTouchedConfigTags());
        $tags = $this->tagCollector->getTags();
        if ($reason === null && $tags !== []) {
            $response->headers->set('X-Cache-Tags', implode(' ', $tags));
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        try {
            if (!$event->isMainRequest() || !$this->isOriginEnabled()) {
                return;
            }
            if ($this->security->getUser() !== null) {
                return;
            }

            $request = $event->getRequest();
            $response = $event->getResponse();
            if (!$this->policy->isCacheableWriteRequest($request) || !$this->policy->isCacheableResponse($response)) {
                return;
            }
            if ($response->headers->get('X-CPalius-Cache') === 'HIT') {
                return;
            }

            $excludes = $this->policy->parseExcludes((string) $this->settingsRegistry->get(
                'performance.cpalius.excludes',
                OriginCachePolicy::DEFAULT_EXCLUDES,
            ));
            if ($this->policy->isExcluded($request->getPathInfo(), $excludes)) {
                return;
            }

            $html = $response->getContent();
            if (!\is_string($html) || $html === '') {
                return;
            }

            $processed = $this->processor->process($html, [
                'minify' => (bool) $this->settingsRegistry->get('performance.cpalius.minify', true),
                'compress_assets' => (bool) $this->settingsRegistry->get('performance.cpalius.compress_assets', true),
                'compress_images' => (bool) $this->settingsRegistry->get('performance.cpalius.compress_images', true),
                'shield' => (bool) $this->settingsRegistry->get('performance.cpalius.shield', false),
            ]);

            $ctx = OriginCacheVaryContext::fromRequest($request, $this->localeProvider, authenticated: false);

            $this->store->putHtml(
                $request->getPathInfo(),
                (string) $request->getQueryString(),
                $processed,
                $this->tagCollector->getMaxAge(),
                $ctx,
            );

            $this->tagCollector->addTags($this->settingsRegistry->drainTouchedConfigTags());
            $tags = $this->tagCollector->getTags();
            if ($tags !== []) {
                $this->tagIndex->record($tags, $request->getPathInfo());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Origin cache write failed.', ['exception' => $e->getMessage()]);
        }
    }

    private function isOriginEnabled(): bool
    {
        $status = $this->statusRepository->findOneByBackendId('cpalius');

        return $status !== null && $status->isEnabled() && $this->store->isEnabledOnDisk();
    }

    private function bypassReason(Request $request, Response $response): ?string
    {
        if ($this->security->getUser() !== null) {
            return 'authenticated';
        }
        if (!$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return 'method';
        }
        if (!$this->policy->isPublicCacheablePath($request->getPathInfo())) {
            return 'private-path';
        }

        $excludes = $this->policy->parseExcludes((string) $this->settingsRegistry->get(
            'performance.cpalius.excludes',
            OriginCachePolicy::DEFAULT_EXCLUDES,
        ));
        if ($this->policy->isExcluded($request->getPathInfo(), $excludes)) {
            return 'excluded';
        }
        if ($response->getStatusCode() !== 200) {
            return 'status';
        }
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return 'content-type';
        }
        if ($response->headers->has('Set-Cookie')) {
            return 'set-cookie';
        }

        return null;
    }
}
