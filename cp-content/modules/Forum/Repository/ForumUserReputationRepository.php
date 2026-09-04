<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Modules\Forum\Entity\ForumUserReputation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumUserReputation>
 */
final class ForumUserReputationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumUserReputation::class);
    }

    /**
     * @return array{positive: int, negative: int, count: int, sum: int}
     */
    public function summarizeReceived(User $user): array
    {
        $row = $this->createQueryBuilder('r')
            ->select(
                'SUM(CASE WHEN r.value = 1 THEN 1 ELSE 0 END) AS positive',
                'SUM(CASE WHEN r.value = -1 THEN 1 ELSE 0 END) AS negative',
                'COUNT(r.id) AS cnt',
                'COALESCE(SUM(r.value), 0) AS total',
            )
            ->andWhere('r.toUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        return [
            'positive' => (int) ($row['positive'] ?? 0),
            'negative' => (int) ($row['negative'] ?? 0),
            'count' => (int) ($row['cnt'] ?? 0),
            'sum' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * @return array{positive: int, negative: int}
     */
    public function summarizeGiven(User $user): array
    {
        $row = $this->createQueryBuilder('r')
            ->select(
                'SUM(CASE WHEN r.value = 1 THEN 1 ELSE 0 END) AS positive',
                'SUM(CASE WHEN r.value = -1 THEN 1 ELSE 0 END) AS negative',
            )
            ->andWhere('r.fromUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        return [
            'positive' => (int) ($row['positive'] ?? 0),
            'negative' => (int) ($row['negative'] ?? 0),
        ];
    }

    /**
     * Bulk net reputation for postbit — { userId: net }.
     *
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function netByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.toUser) AS userId, COALESCE(SUM(r.value), 0) AS net')
            ->andWhere('r.toUser IN (:ids)')
            ->setParameter('ids', $userIds)
            ->groupBy('r.toUser')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['net'];
        }

        return $map;
    }

    public function createReceivedQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.toUser = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    }
}
