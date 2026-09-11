<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumCensorWord;

/** @extends ServiceEntityRepository<ForumCensorWord> */
final class ForumCensorWordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumCensorWord::class);
    }

    /** @return list<ForumCensorWord> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.word', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
