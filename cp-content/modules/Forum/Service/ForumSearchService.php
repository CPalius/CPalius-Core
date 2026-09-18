<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;

final class ForumSearchService
{
    public function __construct(
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly EntityManagerInterface $em,
        private readonly ForumWordFilterService $wordFilterService,
    ) {
    }

    /**
     * @return array{topics: ForumTopic[], posts: ForumPost[], totalTopics: int, totalPosts: int}
     */
    public function search(
        string $query,
        string $scope = 'all',
        ?int $sectionId = null,
        int $limit = 20,
        ?string $locale = null,
        ?string $author = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): array {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return ['topics' => [], 'posts' => [], 'totalTopics' => 0, 'totalPosts' => 0];
        }

        $topics = [];
        $posts = [];
        $totalTopics = 0;
        $totalPosts = 0;
        $likePattern = '%'.addcslashes($query, '%_').'%';

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
            $this->constrainBoard($qb, 't', $sectionId, $locale);
            $this->constrainAuthorAndDates($qb, 't.firstPosterName', 't.createdAt', $author, $from, $to);

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
                $this->constrainBoard($countQb, 't', $sectionId, $locale);
                $this->constrainAuthorAndDates($countQb, 't.firstPosterName', 't.createdAt', $author, $from, $to);

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
                ->andWhere('p.discussionState = :visible')
                ->setParameter('q', $likePattern)
                ->setParameter('normal', ForumTopic::MODE_NORMAL)
                ->setParameter('visible', ForumDiscussionState::Visible)
                ->orderBy('p.createdAt', 'DESC')
                ->setMaxResults($limit);
            $this->constrainBoard($qb, 't', $sectionId, $locale);
            $this->constrainAuthorAndDates($qb, 'p.posterName', 'p.createdAt', $author, $from, $to);

            $posts = $qb->getQuery()->getResult();
            $totalPosts = \count($posts);

            if ($totalPosts >= $limit) {
                $countQb = $this->postRepository->createQueryBuilder('p')
                    ->select('COUNT(p.id)')
                    ->innerJoin('p.topic', 't')
                    ->andWhere('p.body LIKE :q')
                    ->andWhere('t.mode = :normal')
                    ->andWhere('t.discussionState = :visible')
                    ->andWhere('p.discussionState = :visible')
                    ->setParameter('q', $likePattern)
                    ->setParameter('normal', ForumTopic::MODE_NORMAL)
                    ->setParameter('visible', ForumDiscussionState::Visible);
                $this->constrainBoard($countQb, 't', $sectionId, $locale);
                $this->constrainAuthorAndDates($countQb, 'p.posterName', 'p.createdAt', $author, $from, $to);

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

    /**
     * Every one of the four search queries — topics, posts, and the two counts —
     * passes through here with the topic alias, which makes it the one place the
     * reader's word filter has to be applied for search to agree with the boards.
     * A post inside a filtered topic is filtered too: hiding the thread but
     * surfacing its replies would defeat the point.
     */
    private function constrainBoard(QueryBuilder $qb, string $topicAlias, ?int $sectionId, ?string $locale): void
    {
        $this->wordFilterService->applyToQuery($qb, $this->wordFilterService->termsForViewer(), $topicAlias);

        if ($locale !== null && $locale !== '') {
            $qb->andWhere($topicAlias.'.locale = :contentLocale')
                ->setParameter('contentLocale', $locale);
        }

        if ($sectionId === null) {
            return;
        }

        $section = $this->sectionRepository->find($sectionId);
        $ids = $section instanceof ForumSection
            ? $this->sectionRepository->findGroupSectionIds($section)
            : [$sectionId];

        $qb->andWhere('IDENTITY('.$topicAlias.'.section) IN (:sectionIds)')
            ->setParameter('sectionIds', $ids !== [] ? $ids : [$sectionId]);
    }

    private function constrainAuthorAndDates(
        QueryBuilder $qb,
        string $authorField,
        string $dateField,
        ?string $author,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): void {
        if ($author !== null && $author !== '') {
            $qb->andWhere($authorField.' LIKE :author')
                ->setParameter('author', '%'.addcslashes($author, '%_').'%');
        }
        if ($from !== null) {
            $qb->andWhere($dateField.' >= :dateFrom')
                ->setParameter('dateFrom', $from);
        }
        if ($to !== null) {
            $qb->andWhere($dateField.' <= :dateTo')
                ->setParameter('dateTo', $to);
        }
    }
}
