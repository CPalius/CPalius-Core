<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Query\ShowcaseFilter;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseReviewRepository;
use Modules\Showcase\Service\ShowcasePresenter;
use Modules\Showcase\Service\ShowcaseStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing screen for the Showcase desk: what is waiting, what is live, what the
 * catalogue is made of.
 *
 * This is the module's sidebar entry, so it answers the two questions a
 * moderator opens the panel with — "is anything waiting for me" and "is the
 * showcase healthy" — before offering anywhere else to go.
 */
#[Route('/admin/showcase', name: 'admin_showcase_')]
#[IsGranted('showcase.item.view.any')]
final class ShowcaseDashboardController extends AbstractController
{
    private const LATEST_LIMIT = 8;
    private const QUEUE_LIMIT = 6;

    public function __construct(
        private readonly ShowcaseStatsService $stats,
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseReviewRepository $reviews,
        private readonly ShowcasePresenter $presenter,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'showcase.menu.root', icon: 'heroicons:rectangle-group', panel: 'studio', priority: 31, capability: 'showcase.item.view.any', group: 'studio.group.content')]
    public function index(Request $request): Response
    {
        $locale = $this->localeProvider->resolve($request->getLocale());
        $overview = $this->stats->overview();
        $perType = $this->stats->perType($locale);
        $activity = $this->stats->dailyActivity(30);

        return $this->render('@ShowcaseModule/admin/dashboard.html.twig', [
            'stats' => $overview,
            'perType' => $perType,
            'activity' => $activity,
            'queue' => $this->queue(),
            'latest' => $this->latest(),
            'pendingReviews' => $this->reviews->createPendingQueryBuilder()
                ->setMaxResults(self::QUEUE_LIMIT)
                ->getQuery()
                ->getResult(),
            'presenter' => $this->presenter,
            'chartTypeLabels' => array_map(static fn (array $row): string => $row['label'], $perType),
            'chartTypeValues' => array_map(static fn (array $row): int => $row['total'], $perType),
            'chartStatusLabels' => [
                ShowcaseItem::STATUS_PUBLISHED,
                ShowcaseItem::STATUS_PENDING,
                ShowcaseItem::STATUS_DRAFT,
                ShowcaseItem::STATUS_REJECTED,
                ShowcaseItem::STATUS_ARCHIVED,
            ],
            'chartStatusValues' => [
                $overview['published'],
                $overview['pending'],
                $overview['draft'],
                $overview['rejected'],
                $overview['archived'],
            ],
            'csrfToken' => 'admin_showcase_item',
        ]);
    }

    /**
     * Entries a moderator has to decide on, oldest first — a queue is worked
     * from the front, not from whatever was submitted most recently.
     *
     * @return list<ShowcaseItem>
     */
    private function queue(): array
    {
        // orderBy() rather than the filter's sort: the public sorts key off
        // publishedAt, which is null for everything in this queue.
        return $this->items->createFilteredQueryBuilder(new ShowcaseFilter(
            locale: $this->localeProvider->getDefaultCode(),
            statuses: [ShowcaseItem::STATUS_PENDING],
            includeExpired: true,
            anyLocale: true,
        ))
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults(self::QUEUE_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ShowcaseItem>
     */
    private function latest(): array
    {
        return $this->items->createFilteredQueryBuilder(new ShowcaseFilter(
            locale: $this->localeProvider->getDefaultCode(),
            statuses: ShowcaseItem::STATUSES,
            includeExpired: true,
            anyLocale: true,
        ))
            ->orderBy('i.updatedAt', 'DESC')
            ->setMaxResults(self::LATEST_LIMIT)
            ->getQuery()
            ->getResult();
    }
}
