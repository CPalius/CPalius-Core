<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumBan;
use Modules\Forum\Navigation\ForumNavigation;
use Modules\Forum\Repository\ForumBanRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserRankRepository;
use Modules\Forum\Service\ForumBanService;
use Modules\Forum\Service\ForumRankService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio forum member directory: profile edit, custom title, rank, ban/mute.
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
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RichTextSanitizer $richTextSanitizer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.forum.menu.people', icon: 'heroicons:users', panel: 'studio', priority: ForumNavigation::PEOPLE, capability: 'forum.user.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $postCounts = $this->postRepository->findMemberPostCounts(self::PER_PAGE, $offset);
        $userIds = array_keys($postCounts);
        $topicCounts = $this->topicRepository->countTopicsForUserIds($userIds);

        $users = $userIds !== [] ? $this->userRepository->findBy(['id' => $userIds]) : [];
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $allRanks = $this->rankRepository->findAllOrdered();
        $restrictions = $this->banService->activeRestrictionsByUserIds($userIds);

        $members = [];
        foreach ($postCounts as $userId => $postCount) {
            $user = $usersById[$userId] ?? null;
            if ($user === null) {
                continue;
            }

            $restriction = $restrictions[$userId] ?? ['ban' => null, 'mute' => null];
            $members[] = [
                'user' => $user,
                'postCount' => $postCount,
                'topicCount' => $topicCounts[$userId] ?? 0,
                'rank' => $this->rankService->resolveRankFromPreloaded($user, $postCount, $allRanks),
                'ban' => $restriction['ban'],
                'mute' => $restriction['mute'],
            ];
        }

        $totalMembers = $this->postRepository->countDistinctAuthors();

        return $this->render('@ForumModule/admin/members/index.html.twig', [
            'members' => $members,
            'allRanks' => $allRanks,
            'activeBans' => $this->banRepository->findActiveAll(40),
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

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);
        $formValues = $this->memberFormValues($user);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $formValues = $this->submittedMemberFormValues($request);

            $error = $this->persistMemberForm($user, $formValues, $request);
            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->render('@ForumModule/admin/members/edit.html.twig', [
                    'user' => $user,
                    'formValues' => $formValues,
                    'allRanks' => $this->rankRepository->findAllOrdered(),
                    'restriction' => $this->memberRestriction($user),
                ]);
            }

            $this->addFlash('success', $this->translator->trans('studio.forum.members.updated', ['name' => $user->getFullName()]));

            return $this->redirectToRoute('admin_forum_members_edit', ['id' => $user->getId()]);
        }

        return $this->render('@ForumModule/admin/members/edit.html.twig', [
            'user' => $user,
            'formValues' => $formValues,
            'allRanks' => $this->rankRepository->findAllOrdered(),
            'restriction' => $this->memberRestriction($user),
        ]);
    }

    #[Route('/ban-user', name: 'ban_user', methods: ['POST'])]
    public function banUser(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $user = $this->userRepository->find($request->request->getInt('user_id'));
        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('studio.forum.members.not_found'));

            return $this->redirectToRoute('admin_forum_members_index');
        }

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

    #[Route('/target-ban', name: 'target_ban', methods: ['POST'])]
    public function targetBan(Request $request): Response
    {
        $this->assertValidCsrf($request);
        $reason = trim((string) $request->request->get('reason'));
        $ip = trim((string) $request->request->get('ip_address'));
        $email = trim((string) $request->request->get('email'));
        if ($reason === '' || ($ip === '' && $email === '')) {
            $this->addFlash('error', $this->translator->trans('studio.forum.members.target_required'));

            return $this->redirectToRoute('admin_forum_members_index');
        }

        $days = $this->parseOptionalPositiveInt($request, 'duration_days') ?? 0;
        $expiresAt = $days > 0 ? (new \DateTimeImmutable())->modify(sprintf('+%d days', $days)) : null;
        /** @var User $moderator */
        $moderator = $this->getUser();
        $this->banService->banTarget(
            null,
            ForumBan::TYPE_BAN,
            $reason,
            $moderator,
            $expiresAt,
            $ip !== '' ? $ip : null,
            $email !== '' ? $email : null,
        );
        $this->addFlash('success', $this->translator->trans('studio.forum.members.target_banned'));

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

    /**
     * @return array{ban: ?ForumBan, mute: ?ForumBan}
     */
    private function memberRestriction(User $user): array
    {
        $userId = $user->getId();
        $restrictions = $this->banService->activeRestrictionsByUserIds([$userId]);

        return $restrictions[$userId] ?? ['ban' => null, 'mute' => null];
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_member', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }

    /**
     * Empty optional fields become null without triggering FILTER_VALIDATE_INT warnings.
     */
    private function parseOptionalPositiveInt(Request $request, string $field): ?int
    {
        $raw = trim((string) $request->request->get($field, ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function memberFormValues(User $user): array
    {
        $manualRankId = $user->getDataValue('forum_rank_id');

        return [
            'email' => $user->getEmail(),
            'username' => (string) ($user->getUsername() ?? ''),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'status' => $user->getStatus(),
            'bio' => $user->getBio(),
            'location' => $user->getLocation(),
            'signature' => $user->getSignature(),
            'customTitle' => $user->getCustomTitle(),
            'customTitleColor' => $user->getCustomTitleColor() !== '' ? $user->getCustomTitleColor() : '#6B7280',
            'customTitleStyle' => $user->getCustomTitleStyle(),
            'customTitleIcon' => $user->getCustomTitleIcon(),
            'rankId' => is_numeric($manualRankId) ? (int) $manualRankId : '',
            'plainPassword' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedMemberFormValues(Request $request): array
    {
        return [
            'email' => trim((string) $request->request->get('email')),
            'username' => trim((string) $request->request->get('username')),
            'firstName' => trim((string) $request->request->get('first_name')),
            'lastName' => trim((string) $request->request->get('last_name')),
            'status' => trim((string) $request->request->get('status')),
            'bio' => trim((string) $request->request->get('bio')),
            'location' => trim((string) $request->request->get('location')),
            'signature' => trim((string) $request->request->get('signature')),
            'customTitle' => trim((string) $request->request->get('custom_title')),
            'customTitleColor' => trim((string) $request->request->get('custom_title_color')),
            'customTitleStyle' => trim((string) $request->request->get('custom_title_style')),
            'customTitleIcon' => trim((string) $request->request->get('custom_title_icon')),
            'rankId' => $this->parseOptionalPositiveInt($request, 'rank_id') ?? '',
            'plainPassword' => (string) $request->request->get('plain_password'),
        ];
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function persistMemberForm(User $user, array $formValues, Request $request): ?string
    {
        $email = (string) $formValues['email'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->translator->trans('studio.forum.members.error.email_invalid');
        }
        if ($this->userRepository->isEmailTakenByAnotherUser($email, $user->getId())) {
            return $this->translator->trans('studio.forum.members.error.email_taken');
        }

        $username = (string) $formValues['username'];
        if ($username !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return $this->translator->trans('studio.forum.members.error.username_invalid');
        }
        if ($this->userRepository->isUsernameTakenByAnotherUser($username, $user->getId())) {
            return $this->translator->trans('studio.forum.members.error.username_taken');
        }

        $status = (string) $formValues['status'];
        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_BANNED], true)) {
            return $this->translator->trans('studio.forum.members.error.status_invalid');
        }

        $password = (string) $formValues['plainPassword'];
        if ($password !== '' && strlen($password) < 8) {
            return $this->translator->trans('studio.forum.members.error.password_short');
        }

        $user->setEmail($email);
        $user->setUsername($username !== '' ? $username : null);
        $user->setFirstName((string) $formValues['firstName']);
        $user->setLastName((string) $formValues['lastName']);
        $user->setStatus($status);
        $user->setBio($this->richTextSanitizer->sanitize((string) $formValues['bio']));
        $user->setLocation((string) $formValues['location']);
        $user->setSignature((string) $formValues['signature']);
        $user->setCustomTitle((string) $formValues['customTitle']);
        $user->setCustomTitleColor((string) $formValues['customTitleColor']);
        $user->setCustomTitleStyle((string) $formValues['customTitleStyle']);
        $user->setCustomTitleIcon((string) $formValues['customTitleIcon']);

        $rankId = $this->parseOptionalPositiveInt($request, 'rank_id');
        $rank = $rankId !== null ? $this->rankRepository->find($rankId) : null;
        $user->setDataValue('forum_rank_id', $rank?->getId());

        if ($password !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        $this->entityManager->flush();

        return null;
    }
}
