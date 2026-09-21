<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Localization\LocaleProvider;
use App\Core\Security\RoleConfigManager;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumModerator;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\ForumModeratorSubjectType;
use Modules\Forum\ForumPermission;
use Modules\Forum\Repository\ForumModeratorRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/moderators', name: 'admin_forum_moderators_')]
#[IsGranted('forum.moderation.manage')]
final class ForumModeratorAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumModeratorRepository $moderatorRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly UserRepository $userRepository,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->moderatorRepository->findAllWithSection();

        return $this->render('@ForumModule/admin/moderators/index.html.twig', [
            'moderators' => $rows,
            'subjectLabels' => $this->subjectLabels($rows),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $section = $this->resolveSection($request);
            [$type, $subjectId, $subjectKey] = $this->resolveSubject($request);
            if ($this->findExisting($section, $type, $subjectId, $subjectKey) instanceof ForumModerator) {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.moderators.already_assigned'));
            }

            $row = new ForumModerator($section, $type, $subjectId, $subjectKey);
            $row->setInheritChildren($request->request->getBoolean('inherit_children'));
            $row->setGrantKeys($this->parseGrantKeys($request));
            $actor = $this->getUser();
            if ($actor instanceof User) {
                $row->setCreatedBy($actor);
            }

            $this->entityManager->persist($row);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.moderators.created'));

            return $this->redirectToRoute('admin_forum_moderators_index');
        }

        return $this->renderModeratorForm(null, $this->defaultFormValues($request));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $row = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $row->setInheritChildren($request->request->getBoolean('inherit_children'));
            $row->setGrantKeys($this->parseGrantKeys($request));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.moderators.updated'));

            return $this->redirectToRoute('admin_forum_moderators_index');
        }

        $subjectLabel = $this->subjectLabels([$row])[$row->getId() ?? 0] ?? '';

        return $this->renderModeratorForm($row, [
            'sectionId' => $row->getSection()->getId(),
            'subjectType' => $row->getSubjectType()->value,
            'userLookup' => $subjectLabel,
            'groupKey' => $row->getSubjectKey(),
            'inheritChildren' => $row->inheritsChildren(),
            'grantKeys' => $row->getGrantKeys() === [] ? $row->resolvedGrantKeys() : $row->getGrantKeys(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $row = $this->findOrFail($id);
        $this->assertValidCsrf($request);
        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.moderators.deleted'));

        return $this->redirectToRoute('admin_forum_moderators_index');
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderModeratorForm(?ForumModerator $moderator, array $formValues): Response
    {
        return $this->render('@ForumModule/admin/moderators/form.html.twig', [
            'moderator' => $moderator,
            'formValues' => $formValues,
            'tree' => $this->hierarchyService->buildAdminTree($this->localeProvider->getDefaultCode()),
            'groups' => array_map(
                fn (string $id): array => [
                    'id' => $id,
                    'label' => $this->translator->trans($this->roleConfigManager->getLabel($id) ?? $id),
                ],
                $this->roleConfigManager->getAllRoleIds(),
            ),
            'grantOptions' => array_map(
                static fn (ForumPermission $p): string => $p->value,
                ForumPermission::moderateCases(),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function defaultFormValues(Request $request): array
    {
        $sectionRaw = $request->query->get('section');

        return [
            'sectionId' => $sectionRaw !== null && ctype_digit((string) $sectionRaw) ? (int) $sectionRaw : null,
            'subjectType' => ForumModeratorSubjectType::User->value,
            'userLookup' => '',
            'groupKey' => '',
            'inheritChildren' => true,
            'grantKeys' => array_map(
                static fn (ForumPermission $p): string => $p->value,
                ForumPermission::standardLocalModGrants(),
            ),
        ];
    }

    private function resolveSection(Request $request): ForumSection
    {
        $section = $this->sectionRepository->find($request->request->getInt('section_id'));
        if (!$section instanceof ForumSection) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.moderators.section_required'));
        }

        return $section;
    }

    /**
     * @return array{0: ForumModeratorSubjectType, 1: int, 2: string}
     */
    private function resolveSubject(Request $request): array
    {
        $type = ForumModeratorSubjectType::tryFrom((string) $request->request->get('subject_type'))
            ?? ForumModeratorSubjectType::User;

        if ($type === ForumModeratorSubjectType::Group) {
            $key = trim((string) $request->request->get('group_key'));
            if ($key === '' || !$this->roleConfigManager->hasRole($key)) {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.moderators.group_required'));
            }

            return [$type, 0, $key];
        }

        $lookup = trim((string) $request->request->get('user_lookup'));
        $user = null;
        if ($lookup !== '' && ctype_digit($lookup)) {
            $found = $this->userRepository->find((int) $lookup);
            $user = $found instanceof User ? $found : null;
        }
        if (!$user instanceof User && $lookup !== '') {
            $user = $this->userRepository->findOneByEmailOrUsername($lookup);
        }
        if (!$user instanceof User || $user->getId() === null) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.moderators.user_not_found'));
        }

        return [$type, $user->getId(), ''];
    }

    /**
     * @return list<string>
     */
    private function parseGrantKeys(Request $request): array
    {
        $allowed = array_map(static fn (ForumPermission $p): string => $p->value, ForumPermission::moderateCases());
        $posted = array_values(array_filter(
            array_map('strval', (array) $request->request->all('grant_keys')),
            static fn (string $key): bool => \in_array($key, $allowed, true),
        ));
        sort($posted);

        $standard = array_map(static fn (ForumPermission $p): string => $p->value, ForumPermission::standardLocalModGrants());
        sort($standard);

        return $posted === [] || $posted === $standard ? [] : $posted;
    }

    private function findExisting(
        ForumSection $section,
        ForumModeratorSubjectType $type,
        int $subjectId,
        string $subjectKey,
    ): ?ForumModerator {
        return $this->moderatorRepository->findOneBy([
            'section' => $section,
            'subjectType' => $type,
            'subjectId' => $subjectId,
            'subjectKey' => $type === ForumModeratorSubjectType::User ? '' : $subjectKey,
        ]);
    }

    /**
     * @param list<ForumModerator> $rows
     *
     * @return array<int, string>
     */
    private function subjectLabels(array $rows): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            if ($row->getSubjectType() === ForumModeratorSubjectType::User) {
                $userIds[] = $row->getSubjectId();
            }
        }

        $byId = [];
        if ($userIds !== []) {
            foreach ($this->userRepository->findBy(['id' => array_values(array_unique($userIds))]) as $user) {
                $byId[$user->getId() ?? 0] = $user->getUsername() ?: $user->getFullName();
            }
        }

        $labels = [];
        foreach ($rows as $row) {
            $id = $row->getId();
            if ($id === null) {
                continue;
            }
            if ($row->getSubjectType() === ForumModeratorSubjectType::User) {
                $labels[$id] = $byId[$row->getSubjectId()] ?? '#'.$row->getSubjectId();
                continue;
            }
            $labels[$id] = $this->translator->trans(
                $this->roleConfigManager->getLabel($row->getSubjectKey()) ?? $row->getSubjectKey(),
            );
        }

        return $labels;
    }

    private function findOrFail(int $id): ForumModerator
    {
        $row = $this->moderatorRepository->find($id);
        if (!$row instanceof ForumModerator) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.moderators.not_found'));
        }

        return $row;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_moderator', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
