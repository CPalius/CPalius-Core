<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPoll;
use Modules\Forum\Entity\ForumPollVote;

/** @extends ServiceEntityRepository<ForumPollVote> */
final class ForumPollVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPollVote::class);
    }

    /** @return list<int> */
    public function optionIdsVotedByUser(ForumPoll $poll, User $user): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.option) AS optionId')
            ->andWhere('v.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['optionId'], $rows);
    }

    public function countByPollAndUser(ForumPoll $poll, User $user): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, list<User>>
     */
    public function votersGroupedByOption(ForumPoll $poll): array
    {
        $rows = $this->createQueryBuilder('v')
            ->addSelect('u', 'o')
            ->innerJoin('v.user', 'u')
            ->innerJoin('v.option', 'o')
            ->andWhere('v.poll = :poll')
            ->setParameter('poll', $poll)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $vote) {
            $optionId = $vote->getOption()->getId();
            if ($optionId === null) {
                continue;
            }
            $map[$optionId][] = $vote->getUser();
        }

        return $map;
    }

    /**
     * @return list<ForumPollVote>
     */
    public function findByPollAndUser(ForumPoll $poll, User $user): array
    {
        return $this->createQueryBuilder('v')
            ->addSelect('o')
            ->innerJoin('v.option', 'o')
            ->andWhere('v.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
