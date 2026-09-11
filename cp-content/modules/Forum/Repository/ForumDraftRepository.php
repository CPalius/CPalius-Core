<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumDraft;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;

/** @extends ServiceEntityRepository<ForumDraft> */
final class ForumDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumDraft::class);
    }

    public function findReplyDraft(User $user, ForumTopic $topic): ?ForumDraft
    {
        return $this->findOneBy(['user' => $user, 'topic' => $topic]);
    }

    public function findNewTopicDraft(User $user, ForumSection $section): ?ForumDraft
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.section = :section')
            ->andWhere('d.topic IS NULL')
            ->setParameter('user', $user)
            ->setParameter('section', $section)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ForumDraft> */
    public function findRecentForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.topic', 't')->addSelect('t')
            ->leftJoin('d.section', 's')->addSelect('s')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
