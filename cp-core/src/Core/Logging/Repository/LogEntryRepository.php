<?php

declare(strict_types=1);

namespace App\Core\Logging\Repository;

use App\Core\Logging\Entity\LogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogEntry>
 */
class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct($registry, LogEntry::class);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    public function insertRow(
        string $level,
        string $channel,
        string $message,
        array $context,
        array $extra,
        \DateTimeImmutable $createdAt,
    ): void {
        // Log payloads carry request data verbatim, so invalid UTF-8 reaches here from
        // scanner traffic. Substituting keeps the row; throwing used to lose the batch.
        $this->connection->insert('cp_log_entries', [
            'level' => mb_substr(mb_scrub($level, 'UTF-8'), 0, 16),
            'channel' => mb_substr(mb_scrub($channel, 'UTF-8'), 0, 64),
            'message' => mb_scrub($message, 'UTF-8'),
            'context' => json_encode($context, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE),
            'extra' => json_encode($extra, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $createdAt,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * @param array{level?: ?string, channel?: ?string, q?: ?string, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters
     */
    public function createFilteredQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')->orderBy('l.createdAt', 'DESC')->addOrderBy('l.id', 'DESC');

        if (!empty($filters['level'])) {
            $qb->andWhere('l.level = :level')->setParameter('level', (string) $filters['level']);
        }
        if (!empty($filters['channel'])) {
            $qb->andWhere('l.channel = :channel')->setParameter('channel', (string) $filters['channel']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('l.message LIKE :q')->setParameter('q', '%'.addcslashes((string) $filters['q'], '%_\\').'%');
        }
        if (($filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }

    /**
     * @return list<string>
     */
    public function distinctChannels(int $limit = 50): array
    {
        /** @var list<string> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.channel')
            ->orderBy('l.channel', 'ASC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getSingleColumnResult();

        return $rows;
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes every row. Used by the "purge now" action in AACP.
     *
     * Separate from purgeOlderThan() rather than passing a future date: an
     * operator emptying a table deliberately and a cron trimming an age window
     * are different intents, and only one of them should be reachable by
     * accident.
     */
    public function purgeAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->getQuery()
            ->execute();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
