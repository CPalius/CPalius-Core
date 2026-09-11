<?php

declare(strict_types=1);

namespace Modules\Blog\Repository;

use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Blog\Entity\BlogComment;

/**
 * @extends ServiceEntityRepository<BlogComment>
 */
final class BlogCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogComment::class);
    }

    public function createApprovedTopLevelQueryBuilder(Node $node): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->andWhere('c.node = :node')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status = :status')
            ->setParameter('node', $node)
            ->setParameter('status', BlogComment::STATUS_APPROVED)
            ->orderBy('c.createdAt', 'ASC');
    }

    /**
     * @param list<int> $parentIds
     *
     * @return list<BlogComment>
     */
    public function findApprovedRepliesForParents(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->leftJoin('c.parent', 'p')->addSelect('p')
            ->andWhere('c.parent IN (:parents)')
            ->andWhere('c.status = :status')
            ->setParameter('parents', $parentIds)
            ->setParameter('status', BlogComment::STATUS_APPROVED)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countApprovedForNode(Node $node): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.node = :node')
            ->andWhere('c.status = :status')
            ->setParameter('node', $node)
            ->setParameter('status', BlogComment::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createAdminQueryBuilder(?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->leftJoin('c.node', 'n')->addSelect('n')
            ->leftJoin('c.parent', 'p')->addSelect('p')
            ->orderBy('c.createdAt', 'DESC');

        if ($status !== null && $status !== '' && $status !== 'all' && \in_array($status, BlogComment::statuses(), true)) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }
}
