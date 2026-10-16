<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Diagnostics\Doctor;
use App\Core\Performance\Metrics\RouteStats;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * "Which pages cost the most": per-route time, queries and memory for the requests
 * that reach PHP (see RouteStatsSubscriber). Measurements, not settings — the
 * sampling rate and Server-Timing switch live on the Performance screen.
 */
final class PerformanceRoutesController
{
    private const CSRF_TOKEN_ID = 'aacp_performance_routes';

    public function __construct(
        private readonly Environment $twig,
        private readonly RouteStats $stats,
        private readonly Doctor $doctor,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/aacp/performance/routes', name: 'aacp_performance_routes', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.perf_routes.menu', icon: 'heroicons:chart-bar', panel: 'aacp', priority: 83, capability: 'system.performance.manage', parent: 'aacp_hub_maintenance')]
    #[IsGranted('system.performance.manage')]
    public function index(): Response
    {
        $response = new Response($this->twig->render('aacp/performance/routes.html.twig', [
            'rows' => $this->stats->report(),
            // Run here, inside the web server's PHP, so OPcache and friends are judged by the values that
            // actually serve visitors (from the command line they would be the CLI's).
            'findings' => $this->doctor->run(['performance']),
            'csrf_token' => $this->csrf->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/aacp/performance/routes/reset', name: 'aacp_performance_routes_reset', methods: ['POST'])]
    #[IsGranted('system.performance.manage')]
    public function reset(Request $request): RedirectResponse
    {
        if ($this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')))) {
            $this->stats->reset();
        }

        return new RedirectResponse('/aacp/performance/routes');
    }
}
