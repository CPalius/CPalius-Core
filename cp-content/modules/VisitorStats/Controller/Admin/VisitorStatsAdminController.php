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
    private const HISTORY_DAYS = 31;
    private const HISTORY_MONTHS = 12;

    public function __construct(
        private readonly Environment $twig,
        private readonly VisitorStatsRepository $repository,
    ) {
    }

    #[Route('', name: '_index', methods: ['GET'])]
    #[CpAdminMenu(label: 'visitorstats.menu.title', icon: 'heroicons:presentation-chart-line', panel: 'aacp', priority: 26, capability: 'visitorstats.view')]
    public function index(): Response
    {
        $daily = $this->repository->dailyHistory(self::HISTORY_DAYS);
        $monthly = $this->repository->monthlyHistory(self::HISTORY_MONTHS);

        $html = $this->twig->render('@VisitorStatsModule/admin/index.html.twig', [
            'today' => $this->repository->stats(24),
            'dailyDays' => self::HISTORY_DAYS,
            'monthlyMonths' => self::HISTORY_MONTHS,
            'dailyChart' => self::dailyChartPayload($daily),
            'monthlyChart' => self::monthlyChartPayload($monthly),
        ]);

        return new Response($html);
    }

    /**
     * @param list<array{date: string, totalViews: int, uniqueVisitors: int}> $daily
     *
     * @return array{labels: list<string>, total: list<int>, unique: list<int>}
     */
    private static function dailyChartPayload(array $daily): array
    {
        return [
            'labels' => array_map(
                static fn (array $row): string => (new \DateTimeImmutable($row['date']))->format('d M'),
                $daily,
            ),
            'total' => array_map(static fn (array $row): int => $row['totalViews'], $daily),
            'unique' => array_map(static fn (array $row): int => $row['uniqueVisitors'], $daily),
        ];
    }

    /**
     * @param list<array{month: string, totalViews: int, uniqueVisitors: int}> $monthly
     *
     * @return array{labels: list<string>, total: list<int>}
     */
    private static function monthlyChartPayload(array $monthly): array
    {
        return [
            'labels' => array_map(
                static fn (array $row): string => (new \DateTimeImmutable($row['month'].'-01'))->format('M Y'),
                $monthly,
            ),
            'total' => array_map(static fn (array $row): int => $row['totalViews'], $monthly),
        ];
    }
}
