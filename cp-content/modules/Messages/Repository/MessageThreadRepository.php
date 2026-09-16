<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\MessageThread;

/**
 * @extends ServiceEntityRepository<MessageThread>
 */
final class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    public function findOneByPublicId(string $publicId): ?MessageThread
    {
        if ($publicId === '' || !preg_match('/^[a-f0-9]{16}$/', $publicId)) {
            return null;
        }

        return $this->findOneBy(['publicId' => $publicId]);
    }

    public function findOneByPairKey(string $pairKey): ?MessageThread
    {
        return $this->findOneBy(['pairKey' => $pairKey]);
    }

    public function createInboxQueryBuilder(User $user, bool $archived, ?string $search = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.participants', 'me')
            ->addSelect('me')
            ->andWhere('me.user = :user')
            ->andWhere('me.hidden = false')
            ->andWhere('me.archived = :archived')
            ->setParameter('user', $user)
            ->setParameter('archived', $archived)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->distinct();

        $term = trim((string) $search);
        if ($term !== '') {
            $qb->leftJoin('t.participants', 'other', 'WITH', 'other.user != :user')
                ->leftJoin('other.user', 'peer')
                ->andWhere('t.subject LIKE :q OR t.contextLabel LIKE :q OR peer.username LIKE :q OR peer.email LIKE :q')
                ->setParameter('q', '%'.$term.'%');
        }

        return $qb;
    }

    public function createAdminQueryBuilder(?string $search = null, ?string $contextType = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.lastMessageAt', 'DESC');

        $term = trim((string) $search);
        if ($term !== '') {
            $qb->andWhere('t.subject LIKE :q OR t.publicId = :exact OR t.contextLabel LIKE :q')
                ->setParameter('q', '%'.$term.'%')
                ->setParameter('exact', $term);
        }

        if ($contextType !== null && $contextType !== '') {
            $qb->andWhere('t.contextType = :contextType')
                ->setParameter('contextType', $contextType);
        }

        return $qb;
    }

    /**
     * @return list<MessageThread>
     */
    public function recentForPulse(User $user, int $limit = 6): array
    {
        /** @var list<MessageThread> $rows */
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.participants', 'me')
            ->addSelect('me')
            ->leftJoin('t.participants', 'other', 'WITH', 'other.user != :user')
            ->addSelect('other')
            ->leftJoin('other.user', 'peer')
            ->addSelect('peer')
            ->andWhere('me.user = :user')
            ->andWhere('me.hidden = false')
            ->andWhere('me.archived = false')
            ->setParameter('user', $user)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setMaxResults(max(1, min(20, $limit)))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countClosed(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.closed = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countStartedSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.createdBy = :user')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
