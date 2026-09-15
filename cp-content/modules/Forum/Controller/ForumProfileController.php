<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Pagination\Paginator;
use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumUserReputation;
use Modules\Forum\ForumDictionary;
use Modules\Forum\Repository\ForumPostDislikeRepository;
use Modules\Forum\Repository\ForumPostLikeRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserReputationRepository;
use Modules\Forum\Service\ForumProfileStatsService;
use Modules\Forum\Service\ForumRankService;
use Modules\Forum\Service\ForumReputationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public member profile: activity, stats, and reputation (excludes private topics).
 */
final class ForumProfileController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostLikeRepository $postLikeRepository,
        private readonly ForumPostDislikeRepository $postDislikeRepository,
        private readonly ForumUserReputationRepository $reputationRepository,
        private readonly ForumRankService $rankService,
        private readonly ForumProfileStatsService $profileStatsService,
        private readonly ForumReputationService $reputationService,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forum/uye/{username}', name: 'forum_profile', requirements: ['username' => '[a-zA-Z0-9_.-]+'])]
    public function show(Request $request, string $username): Response
    {
        $user = $this->resolveProfileUser($username);
        if (!$user instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.profile.not_found'));
        }

        $canonical = $user->getProfileSlug();
        if ($canonical !== '' && $canonical !== $username) {
            return $this->redirectToCanonicalProfile($request, $canonical);
        }

        $stats = $this->profileStatsService->buildForUser($user);
        $tab = (string) $request->query->get('tab', 'topics');
        $allowedTabs = ['topics', 'posts', 'liked', 'disliked', 'reputation'];
        if (!\in_array($tab, $allowedTabs, true)) {
            $tab = 'topics';
        }

        $items = null;
        $reputations = null;

        if ($tab === 'posts') {
            $qb = $this->topicRepository->createRepliedTopicsByAuthorQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        } elseif ($tab === 'liked') {
            $qb = $this->postLikeRepository->createPublicByUserQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        } elseif ($tab === 'disliked') {
            $qb = $this->postDislikeRepository->createPublicByUserQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        } elseif ($tab === 'reputation') {
            $qb = $this->reputationRepository->createReceivedQueryBuilder($user);
            $reputations = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        } else {
            $qb = $this->topicRepository->createPublicByAuthorQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        }

        /** @var User|null $viewer */
        $viewer = $this->getUser() instanceof User ? $this->getUser() : null;
        $canGiveRep = $this->reputationService->isEnabled()
            && $viewer !== null
            && $viewer->getId() !== $user->getId()
            && $this->isGranted('forum.reputation.give');

        return $this->render('@Theme/forum/profile.html.twig', [
            'profileUser' => $user,
            'rank' => $this->rankService->resolveRank($user),
            'stats' => $stats,
            'topicCount' => $stats['topic_count'],
            'postCount' => $stats['post_count'],
            'tab' => $tab,
            'items' => $items,
            'reputations' => $reputations,
            'canGiveRep' => $canGiveRep,
            'repReasons' => ForumUserReputation::REASONS,
            // Still handed to the template as a convenience list; the form no
            // longer requires a choice from it (see ForumReputationService::give).
            'selectableTopics' => $canGiveRep ? $this->reputationService->selectableTopicsForUser($user) : [],
            'reputationEnabled' => $this->reputationService->isEnabled(),
        ]);
    }

    #[Route('/forum/uye/{username}/rep', name: 'forum_give_reputation', methods: ['POST'], requirements: ['username' => '[a-zA-Z0-9_.-]+'])]
    #[IsGranted('forum.reputation.give')]
    public function giveReputation(Request $request, string $username): Response
    {
        $target = $this->resolveProfileUser($username);
        if (!$target instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.profile.not_found'));
        }

        $this->assertValidCsrf($request, 'forum_give_reputation');

        /** @var User $from */
        $from = $this->getUser();

        $value = (int) $request->request->get('value', 1);
        $reason = (string) $request->request->get('reason', ForumUserReputation::REASON_HELPFUL);
        $comment = trim((string) $request->request->get('comment', ''));
        $topicUrl = trim((string) $request->request->get('topic_url', ''));
        $topicId = $request->request->getInt('topic_id');
        $postId = $request->request->getInt('post_id');

        $topic = $topicId > 0 ? $this->topicRepository->find($topicId) : null;
        $post = $postId > 0 ? $this->postRepository->find($postId) : null;

        if (!$topic instanceof ForumTopic && $post instanceof ForumPost) {
            $topic = $post->getTopic();
        }

        try {
            $this->reputationService->give(
                $from,
                $target,
                $value,
                $reason,
                $topic instanceof ForumTopic ? $topic : null,
                $post instanceof ForumPost ? $post : null,
                $comment !== '' ? $comment : null,
                $topicUrl !== '' ? $topicUrl : null,
            );
            $this->addFlash('success', $this->translator->trans('site.forum.reputation.given'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans('site.forum.reputation.'.$e->getMessage()));
        }

        if ($post instanceof ForumPost) {
            $topic = $post->getTopic();

            return $this->redirect(
                $this->generateUrl('forum_topic', [
                    'topicId' => $topic->getId(),
                    'slug' => $topic->getSlug(),
                ]).'#post'.$post->getId()
            );
        }

        return $this->redirectToRoute('forum_profile', ['username' => $target->getProfileSlug(), 'tab' => 'reputation']);
    }

    private function resolveProfileUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            $byId = $this->userRepository->find((int) $identifier);
            if ($byId instanceof User) {
                return $byId;
            }
        }

        return $this->userRepository->findOneByUsername($identifier);
    }

    private function redirectToCanonicalProfile(Request $request, string $canonical): Response
    {
        $params = ['username' => $canonical];
        foreach (['tab', 'page'] as $queryKey) {
            $value = $request->query->get($queryKey);
            if (\is_string($value) && $value !== '') {
                $params[$queryKey] = $value;
            }
        }

        return $this->redirectToRoute('forum_profile', $params, Response::HTTP_MOVED_PERMANENTLY);
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException($this->translator->trans('site.forum.csrf_invalid'));
        }
    }
}
