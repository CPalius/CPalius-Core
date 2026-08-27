<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\ForumBan;
use App\Entity\User;
use App\Repository\ForumBanRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\ForumUserRankRepository;
use App\Repository\UserRepository;
use Modules\Forum\Service\ForumBanService;
use Modules\Forum\Service\ForumRankService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forum üye dizini — CPalius'un genel kullanıcı yönetimini (AACPUserController)
 * TEKRARLAMAZ; hesap/parola/rol CRUD'u orada kalır. Burada yalnızca
 * forum-bağlamlı işlemler var: mesaj/konu istatistikleri, rütbe atama,
 * forum-özel yasaklama/susturma (bkz. ForumBan — site geneli hesabı
 * ETKİLEMEZ).
 */
#[Route('/admin/forum/members', name: 'admin_forum_members_')]
#[IsGranted('forum.user.manage')]
final class ForumMemberAdminController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumUserRankRepository $rankRepository,
        private readonly ForumBanRepository $banRepository,
        private readonly ForumBanService $banService,
        private readonly ForumRankService $rankService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Üyeler', icon: 'heroicons:users', panel: 'studio', priority: 29, capability: 'forum.user.manage', group: 'İçerik', parent: 'admin_forum_dashboard')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $offset = (($page - 1)) * self::PER_PAGE;

        $postCounts = $this->postRepository->findMemberPostCounts(self::PER_PAGE, $offset);
        $userIds = array_keys($postCounts);
        $topicCounts = $this->topicRepository->countTopicsForUserIds($userIds);

        $users = $userIds !== [] ? $this->userRepository->findBy(['id' => $userIds]) : [];
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $members = [];
        foreach ($postCounts as $userId => $postCount) {
            $user = $usersById[$userId] ?? null;
            if ($user === null) {
                continue;
            }

            $members[] = [
                'user' => $user,
                'postCount' => $postCount,
                'topicCount' => $topicCounts[$userId] ?? 0,
                'rank' => $this->rankService->resolveRank($user),
                'ban' => $this->banService->activeBanFor($user),
                'mute' => $this->banService->activeMuteFor($user),
            ];
        }

        $totalMembers = $this->postRepository->countDistinctAuthors();

        return $this->render('@ForumModule/admin/members/index.html.twig', [
            'members' => $members,
            'allRanks' => $this->rankRepository->findAllOrdered(),
            'page' => $page,
            'totalPages' => max(1, (int) ceil($totalMembers / self::PER_PAGE)),
        ]);
    }

    #[Route('/{id}/rank', name: 'assign_rank', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignRank(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);
        $this->assertValidCsrf($request);

        $rankId = $this->parseOptionalPositiveInt($request, 'rank_id');
        $rank = $rankId !== null ? $this->rankRepository->find($rankId) : null;

        $this->rankService->assignManualRank($user, $rank);
        $this->addFlash('success', $this->translator->trans('studio.forum.members.rank_updated'));

        return $this->redirectToRoute('admin_forum_members_index');
    }

    #[Route('/{id}/ban', name: 'ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ban(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);
        $this->assertValidCsrf($request);

        $type = $request->request->get('type') === 'ban' ? ForumBan::TYPE_BAN : ForumBan::TYPE_MUTE;
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', $this->translator->trans('studio.forum.members.reason_required'));

            return $this->redirectToRoute('admin_forum_members_index');
        }

        $days = $this->parseOptionalPositiveInt($request, 'duration_days') ?? 0;
        $expiresAt = $days > 0 ? (new \DateTimeImmutable())->modify(sprintf('+%d days', $days)) : null;

        /** @var User $moderator */
        $moderator = $this->getUser();
        $this->banService->ban($user, $type, $reason, $moderator, $expiresAt);
        $flashKey = $type === ForumBan::TYPE_BAN ? 'studio.forum.members.user_banned' : 'studio.forum.members.user_muted';
        $this->addFlash('success', $this->translator->trans($flashKey, ['name' => $user->getFullName()]));

        return $this->redirectToRoute('admin_forum_members_index');
    }

    #[Route('/ban/{banId}/revoke', name: 'revoke', methods: ['POST'], requirements: ['banId' => '\d+'])]
    public function revoke(int $banId, Request $request): Response
    {
        $ban = $this->banRepository->find($banId);
        if (!$ban instanceof ForumBan) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.members.record_not_found'));
        }

        $this->assertValidCsrf($request);
        $this->banService->revoke($ban);
        $this->addFlash('success', $this->translator->trans('studio.forum.members.restriction_removed'));

        return $this->redirectToRoute('admin_forum_members_index');
    }

    private function findUserOrFail(int $id): User
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.members.not_found'));
        }

        return $user;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_member', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }

    /**
     * $request->request->getInt() yerine kullanılır: boş string (opsiyonel
     * bir alan boş bırakıldığında) ile çağrıldığında PHP'nin
     * filter_var(FILTER_VALIDATE_INT) uyarısını tetiklemeden güvenle null döner.
     */
    private function parseOptionalPositiveInt(Request $request, string $field): ?int
    {
        $raw = trim((string) $request->request->get($field, ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }
}
