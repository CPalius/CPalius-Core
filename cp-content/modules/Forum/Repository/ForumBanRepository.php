<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Modules\Forum\Entity\ForumBan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumBan>
 */
final class ForumBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumBan::class);
    }

    /**
     * Active bans for the user (not revoked, not expired). Callers still re-check isActive() in PHP.
     *
     * @return ForumBan[]
     */
    public function findActiveForUser(User $user): array
    {
        $bans = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($bans, static fn (ForumBan $ban) => $ban->isActive()));
    }

    /** @return ForumBan[] */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One query for the member-list page: active (not revoked) rows for many users.
     *
     * @param list<int> $userIds
     *
     * @return list<ForumBan>
     */
    public function findActiveForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $bans = $this->createQueryBuilder('b')
            ->andWhere('IDENTITY(b.user) IN (:userIds)')
            ->andWhere('b.revokedAt IS NULL')
            ->setParameter('userIds', $userIds)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($bans, static fn (ForumBan $ban) => $ban->isActive()));
    }

    /**
     * Active forum ban/mute rows for the Studio rank panel.
     *
     * @return ForumBan[]
     */
    public function findActiveAll(int $limit = 50): array
    {
        $bans = $this->createQueryBuilder('b')
            ->leftJoin('b.user', 'u')->addSelect('u')
            ->andWhere('b.revokedAt IS NULL')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(array_filter($bans, static fn (ForumBan $ban) => $ban->isActive()));
    }
}
