<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumBan;
use Modules\Forum\Entity\ForumUserRank;
use Modules\Forum\Repository\ForumBanRepository;
use Modules\Forum\Repository\ForumUserRankRepository;
use Modules\Forum\Service\ForumBanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio ranks, badges, and forum ban/mute panel.
 */
#[Route('/admin/forum/ranks', name: 'admin_forum_ranks_')]
#[IsGranted('forum.ranks.manage')]
final class ForumRankAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumUserRankRepository $rankRepository,
        private readonly ForumBanRepository $banRepository,
        private readonly UserRepository $userRepository,
        private readonly ForumBanService $banService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.forums_ranks', icon: 'heroicons:star', panel: 'studio', priority: 28, capability: 'forum.ranks.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    public function index(): Response
    {
        return $this->render('@ForumModule/admin/ranks/index.html.twig', [
            'ranks' => $this->rankRepository->findAllOrdered(),
            'activeBans' => $this->banRepository->findActiveAll(40),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $rank = new ForumUserRank(
                trim((string) $request->request->get('label')),
                (string) $request->request->get('color', '#8B9DAF'),
                $this->parseMinPosts($request),
            );
            $rank->setIcon($this->parseIcon($request));
            $rank->setSortOrder($request->request->getInt('sort_order'));

            if ($rank->getLabel() === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.ranks.label_required'));
            }

            $this->entityManager->persist($rank);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.ranks.created', ['label' => $rank->getLabel()]));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        return $this->render('@ForumModule/admin/ranks/form.html.twig', [
            'rank' => null,
            'formValues' => ['label' => '', 'color' => '#8B9DAF', 'icon' => '', 'minPosts' => '', 'sortOrder' => 0],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $rank = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $label = trim((string) $request->request->get('label'));
            if ($label === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.ranks.label_required'));
            }

            $rank->setLabel($label);
            $rank->setColor((string) $request->request->get('color', $rank->getColor()));
            $rank->setIcon($this->parseIcon($request));
            $rank->setMinPosts($this->parseMinPosts($request));
            $rank->setSortOrder($request->request->getInt('sort_order'));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.ranks.updated'));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        return $this->render('@ForumModule/admin/ranks/form.html.twig', [
            'rank' => $rank,
            'formValues' => [
                'label' => $rank->getLabel(),
                'color' => $rank->getColor(),
                'icon' => $rank->getIcon() ?? '',
                'minPosts' => $rank->getMinPosts(),
                'sortOrder' => $rank->getSortOrder(),
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $rank = $this->findOrFail($id);
        $this->assertValidCsrf($request);

        $this->entityManager->remove($rank);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.ranks.deleted'));

        return $this->redirectToRoute('admin_forum_ranks_index');
    }

    #[Route('/ban', name: 'ban', methods: ['POST'])]
    public function ban(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $userId = $request->request->getInt('user_id');
        $user = $this->userRepository->find($userId);
        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('studio.forum.members.not_found'));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        $type = $request->request->get('type') === 'ban' ? ForumBan::TYPE_BAN : ForumBan::TYPE_MUTE;
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', $this->translator->trans('studio.forum.members.reason_required'));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        $daysRaw = trim((string) $request->request->get('duration_days', ''));
        $days = $daysRaw !== '' && ctype_digit($daysRaw) ? (int) $daysRaw : 0;
        $expiresAt = $days > 0 ? (new \DateTimeImmutable())->modify(sprintf('+%d days', $days)) : null;

        /** @var User $moderator */
        $moderator = $this->getUser();
        $this->banService->ban($user, $type, $reason, $moderator, $expiresAt);
        $flashKey = $type === ForumBan::TYPE_BAN ? 'studio.forum.members.user_banned' : 'studio.forum.members.user_muted';
        $this->addFlash('success', $this->translator->trans($flashKey, ['name' => $user->getFullName()]));

        return $this->redirectToRoute('admin_forum_ranks_index');
    }

    #[Route('/ban/{banId}/revoke', name: 'revoke_ban', methods: ['POST'], requirements: ['banId' => '\d+'])]
    public function revokeBan(int $banId, Request $request): Response
    {
        $ban = $this->banRepository->find($banId);
        if (!$ban instanceof ForumBan) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.members.record_not_found'));
        }

        $this->assertValidCsrf($request);
        $this->banService->revoke($ban);
        $this->addFlash('success', $this->translator->trans('studio.forum.members.restriction_removed'));

        return $this->redirectToRoute('admin_forum_ranks_index');
    }

    private function parseMinPosts(Request $request): ?int
    {
        $raw = trim((string) $request->request->get('min_posts'));

        return $raw === '' ? null : max(0, (int) $raw);
    }

    private function parseIcon(Request $request): ?string
    {
        $icon = trim((string) $request->request->get('icon'));

        return $icon !== '' ? $icon : null;
    }

    private function findOrFail(int $id): ForumUserRank
    {
        $rank = $this->rankRepository->find($id);
        if (!$rank instanceof ForumUserRank) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.ranks.not_found'));
        }

        return $rank;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_rank', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
