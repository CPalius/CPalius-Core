<?php

declare(strict_types=1);

namespace Modules\VisitorStats\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Modules\VisitorStats\Repository\VisitorStatsRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * The dedicated page this module exists to justify: the AACP dashboard's
 * telemetry widget only ever showed today's totals and a 24h chart, even
 * though cp_visitor_daily_stats has kept one row per day, indefinitely,
 * since 2.2.20 — there was simply nowhere to see that history. This is that
 * "somewhere."
 */
#[Route('/aacp/visitor-stats', name: 'aacp_visitor_stats')]
#[IsGranted('visitorstats.view')]
final class VisitorStatsAdminController
{
    private const HISTORY_DAYS = 30;

    public function __construct(
        private readonly Environment $twig,
        private readonly VisitorStatsRepository $repository,
    ) {
    }

    #[Route('', name: '_index', methods: ['GET'])]
    #[CpAdminMenu(label: 'visitorstats.menu.title', icon: 'heroicons:presentation-chart-line', panel: 'aacp', priority: 26, capability: 'visitorstats.view')]
    public function index(): Response
    {
        $history = $this->repository->dailyHistory(self::HISTORY_DAYS);
        $maxViews = array_reduce(
            $history,
            static fn (int $carry, array $day): int => max($carry, $day['totalViews']),
            1,
        );

        $html = $this->twig->render('@VisitorStatsModule/admin/index.html.twig', [
            'today' => $this->repository->stats(24),
            'history' => $history,
            'maxViews' => $maxViews,
            'historyDays' => self::HISTORY_DAYS,
        ]);

        return new Response($html);
    }
}
