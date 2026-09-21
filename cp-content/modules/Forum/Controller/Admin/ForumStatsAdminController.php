<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Repository\UserRepository;
use Modules\Forum\Attribute\ForumSettingsCard;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Studio stats and live analytics panel.
 */
#[Route('/admin/forum/stats', name: 'admin_forum_stats_')]
#[IsGranted('forum.section.manage')]
final class ForumStatsAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.maintenance',
        description: 'studio.forum.settings.card.maintenance_desc',
        icon: 'heroicons:chart-bar',
        group: 'studio.forum.settings.hub.maintenance',
        priority: 110,
    )]
    public function index(): Response
    {
        $totalTopics = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(ForumTopic::class, 't')
            ->andWhere('t.discussionState = :visible')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();

        $totalPosts = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('Modules\Forum\Entity\ForumPost', 'p')
            ->innerJoin('p.topic', 't')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();

        $boards = array_values(array_filter(
            $this->sectionRepository->findAllByLocale('tr'),
            static fn (ForumSection $s): bool => $s->allowsTopics(),
        ));
        usort($boards, static fn (ForumSection $a, ForumSection $b): int => $b->getPostCount() <=> $a->getPostCount());
        $topForums = \array_slice($boards, 0, 8);

        $topPosterRows = $this->postRepository->findMemberPostCounts(10, 0);
        $topPosterIds = array_keys($topPosterRows);
        $topPosterUsers = $topPosterIds !== [] ? $this->userRepository->findBy(['id' => $topPosterIds]) : [];
        $usersById = [];
        foreach ($topPosterUsers as $user) {
            $usersById[$user->getId()] = $user;
        }

        $topPosters = [];
        foreach ($topPosterRows as $userId => $count) {
            if (!isset($usersById[$userId])) {
                continue;
            }
            $topPosters[] = [
                'user' => $usersById[$userId],
                'postCount' => $count,
            ];
        }

        $daily = $this->buildDailyPostSeries(14);
        $popularTopics = $this->topicRepository->findPopular(8);

        return $this->render('@ForumModule/admin/stats/index.html.twig', [
            'totalTopics' => $totalTopics,
            'totalPosts' => $totalPosts,
            'totalMembers' => $this->postRepository->countDistinctAuthors(),
            'topForums' => $topForums,
            'topPosters' => $topPosters,
            'popularTopics' => $popularTopics,
            'dailyLabels' => array_column($daily, 'label'),
            'dailyValues' => array_column($daily, 'count'),
        ]);
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function buildDailyPostSeries(int $days): array
    {
        $start = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $rows = $this->entityManager->createQueryBuilder()
            ->select('SUBSTRING(p.createdAt, 1, 10) AS day, COUNT(p.id) AS cnt')
            ->from('Modules\Forum\Entity\ForumPost', 'p')
            ->andWhere('p.createdAt >= :start')
            ->setParameter('start', $start)
            ->groupBy('day')
            ->orderBy('day', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day']] = (int) $row['cnt'];
        }

        $series = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $start->modify(sprintf('+%d days', $i));
            $key = $day->format('Y-m-d');
            $series[] = [
                'label' => $day->format('d.m'),
                'count' => $map[$key] ?? 0,
            ];
        }

        return $series;
    }
}
