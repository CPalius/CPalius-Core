<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\ForumDiscussionState;

final class ForumSearchService
{
    public function __construct(
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{topics: ForumTopic[], posts: ForumPost[], totalTopics: int, totalPosts: int}
     */
    public function search(string $query, string $scope = 'all', ?int $sectionId = null, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return ['topics' => [], 'posts' => [], 'totalTopics' => 0, 'totalPosts' => 0];
        }

        $topics = [];
        $posts = [];
        $totalTopics = 0;
        $totalPosts = 0;
        $likePattern = '%' . addcslashes($query, '%_') . '%';

        if ($scope === 'all' || $scope === 'topics') {
            $qb = $this->topicRepository->createQueryBuilder('t')
                ->andWhere('t.title LIKE :q')
                ->andWhere('t.movedToTopic IS NULL')
                ->andWhere('t.mode = :normal')
                ->andWhere('t.discussionState = :visible')
                ->setParameter('q', $likePattern)
                ->setParameter('normal', ForumTopic::MODE_NORMAL)
                ->setParameter('visible', ForumDiscussionState::Visible)
                ->orderBy('t.updatedAt', 'DESC')
                ->setMaxResults($limit);

            if ($sectionId !== null) {
                $qb->andWhere('t.section = :section')
                    ->setParameter('section', $sectionId);
            }

            $topics = $qb->getQuery()->getResult();
            $totalTopics = \count($topics);

            if ($totalTopics >= $limit) {
                $countQb = $this->topicRepository->createQueryBuilder('t')
                    ->select('COUNT(t.id)')
                    ->andWhere('t.title LIKE :q')
                    ->andWhere('t.movedToTopic IS NULL')
                    ->andWhere('t.mode = :normal')
                    ->andWhere('t.discussionState = :visible')
                    ->setParameter('q', $likePattern)
                    ->setParameter('normal', ForumTopic::MODE_NORMAL)
                    ->setParameter('visible', ForumDiscussionState::Visible);

                if ($sectionId !== null) {
                    $countQb->andWhere('t.section = :section')
                        ->setParameter('section', $sectionId);
                }

                $totalTopics = (int) $countQb->getQuery()->getSingleScalarResult();
            }
        }

        if ($scope === 'all' || $scope === 'posts') {
            $qb = $this->postRepository->createQueryBuilder('p')
                ->innerJoin('p.topic', 't')
                ->addSelect('t')
                ->andWhere('p.body LIKE :q')
                ->andWhere('t.mode = :normal')
                ->andWhere('t.discussionState = :visible')
                ->setParameter('q', $likePattern)
                ->setParameter('normal', ForumTopic::MODE_NORMAL)
                ->setParameter('visible', ForumDiscussionState::Visible)
                ->orderBy('p.createdAt', 'DESC')
                ->setMaxResults($limit);

            if ($sectionId !== null) {
                $qb->andWhere('t.section = :section')
                    ->setParameter('section', $sectionId);
            }

            $posts = $qb->getQuery()->getResult();
            $totalPosts = \count($posts);

            if ($totalPosts >= $limit) {
                $countQb = $this->postRepository->createQueryBuilder('p')
                    ->select('COUNT(p.id)')
                    ->innerJoin('p.topic', 't')
                    ->andWhere('p.body LIKE :q')
                    ->andWhere('t.mode = :normal')
                    ->andWhere('t.discussionState = :visible')
                    ->setParameter('q', $likePattern)
                    ->setParameter('normal', ForumTopic::MODE_NORMAL)
                    ->setParameter('visible', ForumDiscussionState::Visible);

                if ($sectionId !== null) {
                    $countQb->andWhere('t.section = :section')
                        ->setParameter('section', $sectionId);
                }

                $totalPosts = (int) $countQb->getQuery()->getSingleScalarResult();
            }
        }

        return [
            'topics' => $topics,
            'posts' => $posts,
            'totalTopics' => $totalTopics,
            'totalPosts' => $totalPosts,
        ];
    }
}
