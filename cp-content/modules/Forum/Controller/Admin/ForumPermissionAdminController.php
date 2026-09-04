<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Service\ForumPermissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio node × role permission matrix.
 */
#[Route('/admin/forum/permissions', name: 'admin_forum_permissions_')]
#[IsGranted('forum.permissions.manage')]
final class ForumPermissionAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumPermissionService $permissionService,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    #[CpAdminMenu(label: 'Forum İzinleri', icon: 'heroicons:key', panel: 'studio', priority: 27, capability: 'forum.permissions.manage', group: 'İçerik', parent: 'admin_forum_dashboard')]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $matrix = $this->parsePostedMatrix($request);
            $this->permissionService->persistMatrix($matrix, $this->localeProvider->getDefaultCode());
            $this->addFlash('success', $this->translator->trans('studio.forum.permissions.saved', [], 'forums'));

            return $this->redirectToRoute('admin_forum_permissions_index');
        }

        return $this->render('@ForumModule/admin/permissions/index.html.twig', [
            'matrix' => $this->permissionService->buildAdminMatrix($this->localeProvider->getDefaultCode()),
            'groups' => $this->permissionService->buildAdminMatrixGrouped($this->localeProvider->getDefaultCode()),
            'roles' => ForumNodePermission::ROLES,
            'permissions' => ForumNodePermission::PERMISSIONS,
        ]);
    }

    /**
     * @return array<int, array<string, array<string, bool>>>
     */
    private function parsePostedMatrix(Request $request): array
    {
        $raw = (array) $request->request->all('matrix');
        $parsed = [];

        foreach ($raw as $sectionId => $roles) {
            if (!\is_array($roles)) {
                continue;
            }
            $sectionId = (int) $sectionId;
            if ($sectionId <= 0) {
                continue;
            }
            foreach ($roles as $role => $perms) {
                if (!\is_array($perms) || !\in_array($role, ForumNodePermission::ROLES, true)) {
                    continue;
                }
                foreach ($perms as $perm => $value) {
                    if (!\in_array($perm, ForumNodePermission::PERMISSIONS, true)) {
                        continue;
                    }
                    $parsed[$sectionId][$role][$perm] = $value === '1' || $value === 1 || $value === true;
                }
            }
        }

        return $parsed;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_permissions', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
