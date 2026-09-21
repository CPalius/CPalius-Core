<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Navigation\ForumNavigation;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Service\ForumPermissionService;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/permissions', name: 'admin_forum_permissions_')]
#[IsGranted('forum.permissions.manage')]
final class ForumPermissionAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumPermissionService $permissionService,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    #[CpAdminMenu(label: 'studio.forum.menu.permissions', icon: 'heroicons:key', panel: 'studio', priority: ForumNavigation::PERMISSIONS, capability: 'forum.permissions.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    public function index(Request $request): Response
    {
        $locale = $this->localeProvider->resolve(
            \is_string($request->request->get('locale') ?? $request->query->get('locale'))
                ? (string) ($request->request->get('locale') ?? $request->query->get('locale'))
                : null,
        );

        $tree = $this->hierarchyService->buildAdminTree($locale);
        $section = $this->resolveSection($request, $tree);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            if (!$section instanceof ForumSection) {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.permissions.empty', [], 'forums'));
            }

            $this->permissionService->persistSectionMatrix($section, $this->parsePostedCells($request));
            $this->addFlash('success', $this->translator->trans('studio.forum.permissions.saved', [], 'forums'));

            return $this->redirectToRoute('admin_forum_permissions_index', [
                'locale' => $locale,
                'section' => $section->getId(),
            ]);
        }

        return $this->render('@ForumModule/admin/permissions/index.html.twig', [
            'tree' => $tree,
            'section' => $section,
            'cells' => $section instanceof ForumSection ? $this->permissionService->buildSectionMatrix($section) : [],
            'roles' => $this->permissionService->matrixRoles(),
            'contentPermissions' => $this->permissionService->matrixContentKeys(),
            'moderatePermissions' => $this->permissionService->matrixModerateKeys(),
            'effects' => [
                ForumAclEffect::Inherit->value,
                ForumAclEffect::Allow->value,
                ForumAclEffect::Deny->value,
            ],
            'locales' => $this->localeProvider->getLocales(),
            'currentLocale' => $locale,
        ]);
    }

    /**
     * @param list<array{section: ForumSection, depth: int, type: mixed}> $tree
     */
    private function resolveSection(Request $request, array $tree): ?ForumSection
    {
        $raw = $request->request->get('section') ?? $request->query->get('section');
        if ($raw !== null && ctype_digit((string) $raw)) {
            $found = $this->sectionRepository->find((int) $raw);
            if ($found instanceof ForumSection) {
                return $found;
            }
        }

        return isset($tree[0]['section']) && $tree[0]['section'] instanceof ForumSection
            ? $tree[0]['section']
            : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parsePostedCells(Request $request): array
    {
        $raw = (array) $request->request->all('matrix');
        $parsed = [];
        $allowed = [
            ForumAclEffect::Inherit->value,
            ForumAclEffect::Allow->value,
            ForumAclEffect::Deny->value,
        ];

        foreach ($raw as $role => $perms) {
            if (!\is_array($perms) || !$this->permissionService->isMatrixRole((string) $role)) {
                continue;
            }
            foreach ($perms as $perm => $value) {
                $effect = (string) $value;
                if (!\in_array($effect, $allowed, true)) {
                    continue;
                }
                if (!\in_array((string) $perm, $this->permissionService->matrixPermissionKeys(), true)) {
                    continue;
                }
                $parsed[(string) $role][(string) $perm] = $effect;
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
