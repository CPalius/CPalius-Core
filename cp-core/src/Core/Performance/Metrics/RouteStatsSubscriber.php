<?php

declare(strict_types=1);

namespace App\Core\Performance\Metrics;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Feeds RouteStats and, when switched on, tells the browser what the request cost.
 *
 * Only requests that reach PHP are seen: a page the origin cache serves from disk
 * never gets here, which is exactly the split worth knowing about — this is the
 * cost of everything the cache does NOT absorb.
 *
 * Recording happens in kernel.terminate, after the response has gone out, and only
 * for a sample of requests (performance.metrics_sample = "1 in N"; 0 turns it off).
 */
final class RouteStatsSubscriber implements EventSubscriberInterface
{
    private float $started = 0.0;

    public function __construct(
        private readonly RequestMetrics $metrics,
        private readonly RouteStats $stats,
        private readonly SettingsRegistry $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // First thing and last thing: the span includes the listeners around the controller.
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -2048],
            KernelEvents::TERMINATE => ['onTerminate', -128],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->started = microtime(true);
        $this->metrics->reset();
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->started === 0.0) {
            return;
        }
        try {
            if (!(bool) $this->settings->get('performance.server_timing', false)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $total = (microtime(true) - $this->started) * 1000;
        $event->getResponse()->headers->set(
            'Server-Timing',
            \sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $this->metrics->dbSeconds * 1000, $this->metrics->queries),
        );
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || $this->started === 0.0) {
            return;
        }
        try {
            $sample = (int) $this->settings->get('performance.metrics_sample', 10);
            if ($sample <= 0 || ($sample > 1 && random_int(1, $sample) !== 1)) {
                return;
            }
            $request = $event->getRequest();
            $route = (string) $request->attributes->get('_route', '');
            if ($route === '' || str_starts_with($route, '_')) {
                return;
            }
            $this->stats->add(
                $route,
                $request->hasPreviousSession() ? 'member' : 'guest',
                (microtime(true) - $this->started) * 1000,
                $this->metrics->queries,
                $this->metrics->dbSeconds * 1000,
                memory_get_peak_usage(true),
            );
        } catch (\Throwable) {
            // Measuring must never be the thing that breaks a request.
        }
    }
}
