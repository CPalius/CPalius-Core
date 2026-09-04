<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserReputationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Profile/postbit stats. Popularity: likes + topics*3 + posts + net_rep*5. Avg rep: mean of received votes.
 */
final class ForumProfileStatsService
{
    public function __construct(
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumUserReputationRepository $reputationRepository,
        private readonly ForumReputationService $reputationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     topic_count: int,
     *     post_count: int,
     *     reply_count: int,
     *     likes_received: int,
     *     reputation_positive: int,
     *     reputation_negative: int,
     *     reputation_net: int,
     *     reputation_avg: float,
     *     reputation_count: int,
     *     rep_given_positive: int,
     *     rep_given_negative: int,
     *     popularity: int
     * }
     */
    public function buildForUser(User $user): array
    {
        $topicCount = $this->topicRepository->countPublicByAuthor($user);
        $postCount = $this->postRepository->countPublicByAuthor($user);
        $replyCount = max(0, $postCount - $topicCount);

        $likesReceived = $this->countLikesReceived($user);
        $repReceived = $this->reputationRepository->summarizeReceived($user);
        $repGiven = $this->reputationRepository->summarizeGiven($user);

        $positive = $repReceived['positive'];
        $negative = $repReceived['negative'];
        $net = $positive - $negative;
        $count = $repReceived['count'];
        $avg = $count > 0 ? round($repReceived['sum'] / $count, 2) : 0.0;

        // Keep User::$data cache in sync for postbit reads.
        if (
            $this->reputationService->getCachedPositive($user) !== $positive
            || $this->reputationService->getCachedNegative($user) !== $negative
        ) {
            $user->setDataValue(ForumReputationService::DATA_POSITIVE, $positive);
            $user->setDataValue(ForumReputationService::DATA_NEGATIVE, $negative);
            $this->entityManager->flush();
        }

        $popularity = $likesReceived + ($topicCount * 3) + $postCount + ($net * 5);

        return [
            'topic_count' => $topicCount,
            'post_count' => $postCount,
            'reply_count' => $replyCount,
            'likes_received' => $likesReceived,
            'reputation_positive' => $positive,
            'reputation_negative' => $negative,
            'reputation_net' => $net,
            'reputation_avg' => $avg,
            'reputation_count' => $count,
            'rep_given_positive' => $repGiven['positive'],
            'rep_given_negative' => $repGiven['negative'],
            'popularity' => $popularity,
        ];
    }

    /**
     * Bulk postbit stats for the given user ids.
     *
     * @param int[] $userIds
     *
     * @return array<int, array{likesReceived: int, reputationNet: int, popularity: int}>
     */
    public function batchEngagementForUserIds(array $userIds, array $topicCounts, array $postCounts): array
    {
        if ($userIds === []) {
            return [];
        }

        $likes = $this->likesReceivedByUserIds($userIds);
        $repNets = $this->reputationRepository->netByUserIds($userIds);

        $map = [];
        foreach ($userIds as $id) {
            $topicCount = $topicCounts[$id] ?? 0;
            $postCount = $postCounts[$id] ?? 0;
            $likesReceived = $likes[$id] ?? 0;
            $net = $repNets[$id] ?? 0;
            $map[$id] = [
                'likesReceived' => $likesReceived,
                'reputationNet' => $net,
                'popularity' => $likesReceived + ($topicCount * 3) + $postCount + ($net * 5),
            ];
        }

        return $map;
    }

    private function countLikesReceived(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(\Modules\Forum\Entity\ForumPostLike::class, 'l')
            ->innerJoin('l.post', 'p')
            ->andWhere('p.author = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    private function likesReceivedByUserIds(array $userIds): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.author) AS userId, COUNT(l.id) AS cnt')
            ->from(\Modules\Forum\Entity\ForumPostLike::class, 'l')
            ->innerJoin('l.post', 'p')
            ->andWhere('p.author IN (:ids)')
            ->setParameter('ids', $userIds)
            ->groupBy('p.author')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }
}
