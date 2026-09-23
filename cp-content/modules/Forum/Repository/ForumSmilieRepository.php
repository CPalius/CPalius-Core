<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumSmilie;

/**
 * @extends ServiceEntityRepository<ForumSmilie>
 */
final class ForumSmilieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumSmilie::class);
    }

    /**
     * @return list<ForumSmilie>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(string $code): ?ForumSmilie
    {
        return $this->findOneBy(['code' => $code]);
    }
}
