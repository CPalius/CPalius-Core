<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumWarning;

/** @extends ServiceEntityRepository<ForumWarning> */
final class ForumWarningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumWarning::class);
    }

    /**
     * @return list<ForumWarning>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'DESC']);
    }

    public function sumActivePoints(User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COALESCE(SUM(w.points), 0)')
            ->andWhere('w.user = :user')
            ->andWhere('w.expiresAt IS NULL OR w.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
