<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPresence;
use Modules\Forum\Entity\ForumTopic;

/**
 * @extends ServiceEntityRepository<ForumPresence>
 */
final class ForumPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPresence::class);
    }

    public function findOneBySessionHash(string $sessionHash): ?ForumPresence
    {
        return $this->findOneBy(['sessionHash' => $sessionHash]);
    }

    /**
     * @return list<ForumPresence>
     */
    public function findActiveMembers(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->andWhere('p.user IS NOT NULL')
            ->andWhere('p.lastSeenAt >= :since')
            ->andWhere('u.status = :status')
            ->setParameter('since', $since)
            ->setParameter('status', User::STATUS_ACTIVE)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveGuests(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user IS NULL')
            ->andWhere('p.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Anonymous visitors grouped by what they are: guest, spider or bot.
     *
     * One query rather than three counts, and members are excluded because
     * they are already listed by name — counting them here as well would make
     * the four numbers add up to more than the people on the board.
     *
     * @return array<string, int> kind => count, only for kinds actually present
     */
    public function countActiveByKind(\DateTimeImmutable $since): array
    {
        /** @var list<array{kind: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.kind AS kind, COUNT(p.id) AS total')
            ->andWhere('p.user IS NULL')
            ->andWhere('p.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.kind')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['kind']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Presence rows currently on this thread (members + guests).
     *
     * @return list<ForumPresence>
     */
    public function findActiveOnTopic(ForumTopic $topic, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->andWhere('p.topic = :topic')
            ->andWhere('p.lastSeenAt >= :since')
            ->setParameter('topic', $topic)
            ->setParameter('since', $since)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function purgeStale(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.lastSeenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
