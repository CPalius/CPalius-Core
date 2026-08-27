<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Repository\ForumPostRepository;
use App\Repository\ForumTopicRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bölüm istatistiklerini (konu/mesaj sayısı, son mesaj) senkronize eder —
 * Cotonti cot_forum_stats + forums.functions.php resync mantığının
 * CPalius karşılığı.
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
            ->from('App\Entity\ForumPost', 'p')
            ->andWhere('p.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();

        $lastPost = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from('App\Entity\ForumPost', 'p')
            ->andWhere('p.section = :section')
            ->setParameter('section', $section)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $section->setTopicCount($topicCount);
        $section->setPostCount($postCount);

        if ($lastPost !== null) {
            $topic = $lastPost->getTopic();
            $section->setLastTopicId($topic->getId());
            $section->setLastTopicTitle($topic->getTitle());
            $section->setLastPostAt($lastPost->getCreatedAt());
            $section->setLastPosterName($lastPost->getPosterName());
        } else {
            $section->setLastTopicId(null);
            $section->setLastTopicTitle(null);
            $section->setLastPostAt(null);
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
            $topic->touch();
        }

        $firstPost = $this->postRepository->findFirstByTopic($topic);
        if ($firstPost !== null) {
            $preview = strip_tags($firstPost->getBody());
            $topic->setPreview(mb_substr($preview, 0, 128));
        }

        $this->entityManager->flush();
        $this->syncSection($topic->getSection());
    }
}
