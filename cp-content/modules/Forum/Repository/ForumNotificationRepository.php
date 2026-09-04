<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Modules\Forum\Entity\ForumNotification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumNotification>
 */
final class ForumNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumNotification::class);
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ForumNotification>
     */
    public function findRecentForUser(User $user, int $limit = 6): array
    {
        return $this->createForUserQueryBuilder($user)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function createForUserQueryBuilder(User $user, ?string $type = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC');

        if ($type !== null && $type !== '' && $type !== 'all') {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    public function markAllReadForUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function deleteForContent(string $contentType, int $contentId): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.contentType = :type')
            ->andWhere('n.contentId = :id')
            ->setParameter('type', $contentType)
            ->setParameter('id', $contentId)
            ->getQuery()
            ->execute();
    }
}
