<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumBanFilter;
use Modules\Forum\ForumBanFilterType;

/** @extends ServiceEntityRepository<ForumBanFilter> */
final class ForumBanFilterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumBanFilter::class);
    }

    /**
     * @return list<ForumBanFilter>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['type' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByTypeAndRule(ForumBanFilterType $type, string $rule): ?ForumBanFilter
    {
        return $this->findOneBy(['type' => $type, 'rule' => trim($rule)]);
    }

    /**
     * @return list<ForumBanFilter>
     */
    public function findByType(ForumBanFilterType $type): array
    {
        return $this->findBy(['type' => $type], ['id' => 'ASC']);
    }
}
