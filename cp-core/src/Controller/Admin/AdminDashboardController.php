<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Admin\StudioDashboardService;
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
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.dashboard.header', icon: 'heroicons:home', panel: 'studio', priority: 10)]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', $this->dashboardService->build());
    }

    #[Route('/admin/dashboard/widget-visibility', name: 'admin_dashboard_widget_visibility', methods: ['POST'])]
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
