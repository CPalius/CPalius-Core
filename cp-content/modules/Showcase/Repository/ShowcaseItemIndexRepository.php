<?php

declare(strict_types=1);

namespace Modules\Showcase\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseItemIndex;
use Modules\Showcase\Entity\ShowcaseType;

/**
 * @extends ServiceEntityRepository<ShowcaseItemIndex>
 */
final class ShowcaseItemIndexRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShowcaseItemIndex::class);
    }

    /**
     * @return array<string, ShowcaseItemIndex> keyed by field name
     */
    public function findForItem(ShowcaseItem $item): array
    {
        $rows = $this->createQueryBuilder('x')
            ->andWhere('x.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();

        $byName = [];
        foreach ($rows as $row) {
            if ($row instanceof ShowcaseItemIndex) {
                $byName[$row->getFieldName()] = $row;
            }
        }

        return $byName;
    }

    /**
     * Drops index rows for fields that no longer answer filters — a deleted
     * field, or one an editor un-marked as queryable.
     *
     * Scoped to ONE type on purpose. Field names are unique per bundle, not
     * globally: a "vehicle" type and a "software" type can both define
     * "version", and deleting one must not blank the other's index. The
     * subquery on the item's type is what keeps them apart.
     *
     * @param list<string> $fieldNames
     */
    public function deleteForType(ShowcaseType $type, array $fieldNames): int
    {
        if ($fieldNames === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('x')
            ->delete()
            ->andWhere('x.fieldName IN (:names)')
            ->andWhere(sprintf(
                'x.item IN (SELECT scoped.id FROM %s scoped WHERE scoped.type = :type)',
                ShowcaseItem::class,
            ))
            ->setParameter('names', $fieldNames)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
    }
}
