<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageReport;

/**
 * @extends ServiceEntityRepository<MessageReport>
 */
final class MessageReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageReport::class);
    }

    public function findExisting(Message $message, User $reporter): ?MessageReport
    {
        return $this->findOneBy(['message' => $message, 'reporter' => $reporter]);
    }

    public function createQueueQueryBuilder(?string $status = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status IN (:open)')
            ->setParameter('open', [MessageReport::STATUS_OPEN, MessageReport::STATUS_REVIEWING])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
