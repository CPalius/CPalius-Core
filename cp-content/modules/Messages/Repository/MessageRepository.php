<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageThread;

/**
 * @extends ServiceEntityRepository<Message>
 */
final class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function createThreadQueryBuilder(MessageThread $thread, bool $includeDeleted = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        if (!$includeDeleted) {
            $qb->andWhere('m.deletedAt IS NULL');
        }

        return $qb;
    }

    public function countSentSince(User $author, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.author = :author')
            ->andWhere('m.createdAt >= :since')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('author', $author)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function latestIncomingId(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(MAX(m.id), 0)')
            ->innerJoin('m.thread', 't')
            ->innerJoin('t.participants', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hidden = false')
            ->andWhere('p.muted = false')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('(m.author IS NULL OR m.author != :user)')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.createdAt >= :since')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{authorId: int, sent: int}>
     */
    public function topSenders(\DateTimeImmutable $since, int $limit = 8): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.author) AS authorId, COUNT(m.id) AS sent')
            ->andWhere('m.createdAt >= :since')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.author IS NOT NULL')
            ->setParameter('since', $since)
            ->groupBy('m.author')
            ->orderBy('sent', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'authorId' => (int) $row['authorId'],
                'sent' => (int) $row['sent'],
            ];
        }

        return $out;
    }
}
