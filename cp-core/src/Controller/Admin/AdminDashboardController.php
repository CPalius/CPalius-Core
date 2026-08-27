<?php

namespace App\Controller\Admin;

use App\Core\Admin\StudioDashboardService;
use App\Core\Annotation\CpAdminMenu;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Studio'nun karşılama ekranı: giriş yapan editör/admin kullanıcının ilk
 * gördüğü sayfa (form_login default_target_path, bkz. security.yaml).
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
    #[CpAdminMenu(label: 'Genel Bakış', icon: 'heroicons:home', panel: 'studio', priority: 10)]
    public function index(): Response
    {
        $data = $this->dashboardService->build();

        return $this->render('admin/dashboard.html.twig', [
            ...$data,
            'widgetCatalog' => $this->dashboardService->buildWidgetCatalog(),
            'hiddenWidgetIds' => $this->getHiddenWidgetIdsForCurrentUser(),
            'widget_visibility_csrf_token' => $this->csrfTokenManager->getToken('studio_widget_visibility')->getValue(),
        ]);
    }

    #[Route('/admin/dashboard/widget-visibility', name: 'admin_dashboard_widget_visibility', methods: ['POST'])]
    public function updateWidgetVisibility(Request $request): JsonResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('studio_widget_visibility', $submittedToken))) {
            return new JsonResponse(['success' => false, 'message' => 'Geçersiz CSRF token.'], 400);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'Oturum bulunamadı.'], 401);
        }

        $widgetId = (string) $request->request->get('widgetId', '');
        if ($widgetId === '' || preg_match('/^[a-z0-9_.]+$/', $widgetId) !== 1) {
            return new JsonResponse(['success' => false, 'message' => 'Geçersiz widget kimliği.'], 400);
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
    private function getHiddenWidgetIdsForCurrentUser(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->getHiddenWidgetIdsForUser($user);
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
