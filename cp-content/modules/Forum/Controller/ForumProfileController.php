<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Pagination\Paginator;
use App\Entity\User;
use App\Repository\ForumPostRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\UserRepository;
use Modules\Forum\ForumDictionary;
use Modules\Forum\Service\ForumRankService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forum üye profili — Cotonti'de ayrı bir "postbit profili" yoktu (genel
 * kullanıcı profiline yönlendirilirdi); bu, forum bağlamına özel, sadece
 * herkese açık (özel konu hariç) etkinliği gösteren yeni bir sayfa.
 */
final class ForumProfileController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumRankService $rankService,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forum/uye/{userId}', name: 'forum_profile', requirements: ['userId' => '\d+'])]
    public function show(Request $request, int $userId): Response
    {
        $user = $this->userRepository->find($userId);
        if (!$user instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.profile.not_found'));
        }

        $topicCount = $this->topicRepository->countPublicByAuthor($user);
        $postCount = $this->postRepository->countPublicByAuthor($user);

        $tab = $request->query->get('tab', 'topics') === 'posts' ? 'posts' : 'topics';

        if ($tab === 'posts') {
            $qb = $this->topicRepository->createRepliedTopicsByAuthorQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        } else {
            $qb = $this->topicRepository->createPublicByAuthorQueryBuilder($user);
            $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        }

        return $this->render('@CpaliusWebsiteTheme/forum/profile.html.twig', [
            'profileUser' => $user,
            'rank' => $this->rankService->resolveRank($user),
            'topicCount' => $topicCount,
            'postCount' => $postCount,
            'tab' => $tab,
            'items' => $items,
        ]);
    }
}
