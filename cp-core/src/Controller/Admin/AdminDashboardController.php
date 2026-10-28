<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Admin\StudioDashboardLayout;
use App\Core\Admin\StudioDashboardService;
use App\Core\Analytics\VisitorStatsProviderInterface;
use App\Core\Annotation\CpAdminMenu;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Studio command desk — first screen after editorial login.
 */
final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly StudioDashboardService $dashboardService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        // Null when the VisitorStats module is not installed/active.
        private readonly ?VisitorStatsProviderInterface $visitorStats = null,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.dashboard.header', icon: 'heroicons:home', panel: 'studio', priority: 10, capability: 'admin.dashboard.view')]
    #[IsGranted('admin.dashboard.view')]
    public function index(): Response
    {
        $user = $this->security->getUser();
        $data = $this->dashboardService->build();
        $visitors = $this->buildVisitors();
        $stored = $user instanceof User ? $user->getDataValue(StudioDashboardLayout::DATA_KEY, []) : [];

        return $this->render('admin/dashboard.html.twig', $data + [
            'hiddenWidgets' => $user instanceof User ? $this->getHiddenWidgetIdsForUser($user) : [],
            'visitors' => $visitors,
            'layoutColumns' => StudioDashboardLayout::COLUMNS,
            'cards' => StudioDashboardLayout::arrange(
                StudioDashboardLayout::catalog($data, $visitors !== null),
                \is_array($stored) ? $stored : [],
            ),
        ]);
    }

    /**
     * Saves card order, removed cards and row limits for the signed-in user.
     * `reset=1` drops the custom layout and returns to the default one.
     */
    #[Route('/admin/dashboard/layout', name: 'admin_dashboard_layout', methods: ['POST'])]
    #[IsGranted('admin.dashboard.view')]
    public function saveLayout(Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('studio_dashboard_layout', (string) $request->request->get('_token')))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 400);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'Session not found.'], 401);
        }

        $layout = $request->request->getBoolean('reset')
            ? ['order' => [], 'columns' => [], 'removed' => [], 'limits' => []]
            : StudioDashboardLayout::normalize($request->request->all());

        $user->setDataValue(StudioDashboardLayout::DATA_KEY, $layout);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true] + $layout);
    }

    /**
     * Same numbers as the AACP desk card, for editors who have the
     * visitorstats.view capability. Null hides the card: module absent,
     * capability missing, or no data source — never a fabricated zero.
     *
     * @return array{views: int, unique: int, last24h: int, peakLabel: string, peakHits: int, bars: list<array{label: string, hits: int, pct: int}>}|null
     */
    private function buildVisitors(): ?array
    {
        if ($this->visitorStats === null || !$this->isGranted('visitorstats.view')) {
            return null;
        }

        $stats = $this->visitorStats->stats(24);
        $labels = $stats['hourly']['labels'];
        $hits = $stats['hourly']['hits'];
        $max = $hits === [] ? 0 : max($hits);

        $bars = [];
        foreach ($hits as $i => $count) {
            $bars[] = ['label' => (string) ($labels[$i] ?? ''), 'hits' => $count, 'pct' => $max > 0 ? (int) max($count > 0 ? 4 : 0, round($count * 100 / $max)) : 0];
        }

        $peak = $max > 0 ? (int) array_search($max, $hits, true) : null;

        return [
            'views' => $stats['pageViews'],
            'unique' => $stats['uniqueIps'],
            'last24h' => array_sum($hits),
            'peakLabel' => $peak !== null ? (string) ($labels[$peak] ?? '') : '',
            'peakHits' => $max,
            'bars' => $bars,
        ];
    }

    #[Route('/admin/dashboard/widget-visibility', name: 'admin_dashboard_widget_visibility', methods: ['POST'])]
    #[IsGranted('admin.dashboard.view')]
    public function updateWidgetVisibility(Request $request): JsonResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('studio_widget_visibility', $submittedToken))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 400);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'Session not found.'], 401);
        }

        $widgetId = (string) $request->request->get('widgetId', '');
        if ($widgetId === '' || preg_match('/^[a-z0-9_.]+$/', $widgetId) !== 1) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid widget ID.'], 400);
        }

        $hidden = $request->request->getBoolean('hidden');
        $hiddenIds = $this->getHiddenWidgetIdsForUser($user);

        if ($hidden) {
            $hiddenIds[$widgetId] = true;
        } else {
            unset($hiddenIds[$widgetId]);
        }

        $user->setDataValue('studio_dashboard_widgets', ['hidden' => array_keys($hiddenIds)]);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'hidden' => array_keys($hiddenIds)]);
    }

    /**
     * @return array<string, true>
     */
    private function getHiddenWidgetIdsForUser(User $user): array
    {
        $stored = $user->getDataValue('studio_dashboard_widgets', ['hidden' => []]);
        $hidden = is_array($stored) ? ($stored['hidden'] ?? []) : [];

        return array_fill_keys(array_filter((array) $hidden, 'is_string'), true);
    }
}
