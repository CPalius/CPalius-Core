<?php

declare(strict_types=1);

namespace App\Core\Notification\Repository;

use App\Core\Notification\Entity\NotificationDigestItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationDigestItem>
 */
class NotificationDigestItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDigestItem::class);
    }

    public function existsFor(User $user, int $notificationId): bool
    {
        return $this->count(['user' => $user, 'notification' => $notificationId]) > 0;
    }

    /**
     * Closed buckets whose mail has not been sent yet, grouped by user id.
     *
     * @return list<NotificationDigestItem>
     */
    public function findPendingForClosedBuckets(array $closedBuckets, int $limit = 500): array
    {
        if ($closedBuckets === []) {
            return [];
        }

        /** @var list<NotificationDigestItem> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.sentAt IS NULL')
            ->andWhere('d.bucket IN (:buckets)')
            ->setParameter('buckets', $closedBuckets)
            ->orderBy('d.user', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
            ->setMaxResults(max(1, min(2000, $limit)))
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
