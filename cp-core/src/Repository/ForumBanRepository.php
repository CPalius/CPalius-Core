<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumBan;
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
     * Kullanıcının şu an aktif olan tüm yasaklarını döndürür (revoke
     * edilmemiş ve süresi dolmamış). Uygulama tarafında isActive() ile
     * tekrar doğrulanır (expiresAt anlık kontrolü DB'de değil PHP'de).
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
}
