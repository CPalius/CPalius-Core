<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Repository\PerformanceBackendStatusRepository;
use App\Core\Settings\SettingsRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends Cache-Control so Varnish does not store admin/auth HTML (stale CSRF tokens).
 * When Varnish is enabled, public GET pages receive s-maxage from the TTL setting.
 */
final class VarnishCacheHeaderListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly VarnishCachePolicy $policy,
        private readonly PerformanceBackendStatusRepository $statusRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -16],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();

        if ($this->policy->isHardPrivatePath($path)) {
            $this->applyNoStore($response);

            return;
        }

        $status = $this->statusRepository->findOneByBackendId('varnish');
        if ($status === null || !$status->isEnabled()) {
            return;
        }

        $excludes = $this->policy->parseExcludes((string) $this->settingsRegistry->get(
            'performance.varnish.excludes',
            VarnishCachePolicy::DEFAULT_EXCLUDES,
        ));

        $isPrivate = !$request->isMethod('GET')
            || $this->security->getUser() !== null
            || $this->policy->isExcludedPath($path, $excludes)
            || $response->getStatusCode() >= 400;

        if ($isPrivate) {
            $this->applyNoStore($response);

            return;
        }

        $ttl = $this->policy->normalizeTtl($this->settingsRegistry->get(
            'performance.varnish.ttl',
            VarnishCachePolicy::DEFAULT_TTL,
        ));

        $response->headers->set('Cache-Control', \sprintf('public, s-maxage=%d', $ttl));
        $response->headers->set('Surrogate-Control', \sprintf('max-age=%d', $ttl));
    }

    private function applyNoStore(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $response->headers->remove('Surrogate-Control');
        $response->setPrivate();
    }
}
