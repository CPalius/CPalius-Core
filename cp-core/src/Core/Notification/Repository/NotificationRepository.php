<?php

declare(strict_types=1);

namespace App\Core\Notification\Repository;

use App\Core\Notification\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findByDedupe(User $user, string $eventKey, string $dedupeKey): ?Notification
    {
        return $this->findOneBy([
            'user' => $user,
            'eventKey' => $eventKey,
            'dedupeKey' => $dedupeKey,
        ]);
    }

    /**
     * @return list<Notification>
     */
    public function findForUser(User $user, int $limit = 50, int $offset = 0): array
    {
        /** @var list<Notification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadExceptEventPrefix(User $user, string $excludePrefix): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('n.eventKey NOT LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $excludePrefix.'%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function findForUserExceptEventPrefix(User $user, string $excludePrefix, int $limit = 50, int $offset = 0): array
    {
        /** @var list<Notification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.eventKey NOT LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $excludePrefix.'%')
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function latestIdExceptEventPrefix(User $user, string $excludePrefix): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COALESCE(MAX(n.id), 0)')
            ->andWhere('n.user = :user')
            ->andWhere('n.eventKey NOT LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $excludePrefix.'%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadByEventPrefix(User $user, string $eventPrefix): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('n.eventKey LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $eventPrefix.'%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function findForUserByEventPrefix(User $user, string $eventPrefix, int $limit = 50, int $offset = 0): array
    {
        /** @var list<Notification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.eventKey LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $eventPrefix.'%')
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function createForUserByEventPrefixQueryBuilder(User $user, string $eventPrefix, ?string $exactEventKey = null): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.eventKey LIKE :prefix')
            ->setParameter('user', $user)
            ->setParameter('prefix', $eventPrefix.'%')
            ->orderBy('n.createdAt', 'DESC');

        if ($exactEventKey !== null && $exactEventKey !== '') {
            $qb->andWhere('n.eventKey = :eventKey')->setParameter('eventKey', $exactEventKey);
        }

        return $qb;
    }

    public function markAllReadByEventPrefix(User $user, string $eventPrefix): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('n.eventKey LIKE :prefix')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('prefix', $eventPrefix.'%')
            ->getQuery()
            ->execute();
    }

    public function markAllRead(User $user): int
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
}
