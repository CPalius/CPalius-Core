<?php

declare(strict_types=1);

namespace App\Core\Mail\Repository;

use App\Core\Mail\Entity\MailLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailLog>
 */
class MailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailLog::class);
    }

    /**
     * @param array{status?: ?string, q?: ?string} $filters
     */
    public function createFilteredQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');

        if (!empty($filters['status'])) {
            $qb->andWhere('m.status = :status')->setParameter('status', (string) $filters['status']);
        }
        if (!empty($filters['q'])) {
            $q = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $qb->andWhere('(m.to LIKE :q OR m.subject LIKE :q)')->setParameter('q', $q);
        }

        return $qb;
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
