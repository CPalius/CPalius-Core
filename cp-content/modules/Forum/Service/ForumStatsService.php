<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;

/**
 * Sync section stats (topic/post counts, last post) — denormalized node counters.
 */
final class ForumStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
    ) {
    }

    public function syncSection(ForumSection $section): void
    {
        $topicCount = $this->topicRepository->countBySection($section);

        $postCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('Modules\Forum\Entity\ForumPost', 'p')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.section = :section')
            ->andWhere('t.discussionState = :visible')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('section', $section)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();

        $lastPost = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from('Modules\Forum\Entity\ForumPost', 'p')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.section = :section')
            ->andWhere('t.discussionState = :visible')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('section', $section)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $section->setTopicCount($topicCount);
        $section->setPostCount($postCount);

        if ($lastPost !== null) {
            $topic = $lastPost->getTopic();
            $section->setLastTopicId($topic->getId());
            $section->setLastTopicTitle($topic->getTitle());
            $section->setLastPostId($lastPost->getId());
            $section->setLastPostAt($lastPost->getCreatedAt());
            $section->setLastPoster($lastPost->getAuthor());
            $section->setLastPosterName($lastPost->getPosterName());
        } else {
            $section->setLastTopicId(null);
            $section->setLastTopicTitle(null);
            $section->setLastPostId(null);
            $section->setLastPostAt(null);
            $section->setLastPoster(null);
            $section->setLastPosterName(null);
        }

        $section->touch();
        $this->entityManager->flush();
    }

    public function syncTopic(ForumTopic $topic): void
    {
        $postCount = $this->postRepository->countByTopic($topic);
        $topic->setPostCount($postCount);

        $lastPost = $this->postRepository->findLastByTopic($topic);
        if ($lastPost !== null) {
            $topic->setLastPoster($lastPost->getAuthor());
            $topic->setLastPosterName($lastPost->getPosterName());
            $topic->setLastPostId($lastPost->getId());
            $topic->setLastPostDate($lastPost->getCreatedAt());
            $topic->touch();
        }

        $firstPost = $this->postRepository->findFirstByTopic($topic);
        if ($firstPost !== null) {
            $topic->setFirstPostId($firstPost->getId());
            $preview = strip_tags($firstPost->getBody());
            $topic->setPreview(mb_substr($preview, 0, 128));
        }

        $this->entityManager->flush();
        $this->syncSection($topic->getSection());
    }
}
