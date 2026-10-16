<?php

declare(strict_types=1);

namespace Modules\Widget\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Widget\Entity\WidgetInstance;

/** @extends ServiceEntityRepository<WidgetInstance> */
final class WidgetInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetInstance::class);
    }

    /**
     * Every widget of an area in display order, switched-off ones included (the Studio list).
     *
     * @return list<WidgetInstance>
     */
    public function findForArea(string $area): array
    {
        return $this->findBy(['area' => $area], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Only what the public site draws.
     *
     * @return list<WidgetInstance>
     */
    public function findActiveForArea(string $area): array
    {
        return $this->findBy(['area' => $area, 'isActive' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Everything the public site may draw, in display order. Few rows, read once per request; the
     * "also drawn in" areas live in JSON, so the area filter happens in PHP.
     *
     * @return list<WidgetInstance>
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The Studio list of one area: its own widgets first, then the ones borrowed from other areas.
     *
     * @return list<WidgetInstance>
     */
    public function findShownIn(string $area): array
    {
        return array_values(array_filter($this->findBy([], ['position' => 'ASC', 'id' => 'ASC']), static fn (WidgetInstance $w): bool => $w->drawnIn($area) && $w->getArea() !== $area));
    }

    public function countForArea(string $area): int
    {
        return $this->count(['area' => $area]);
    }

    public function nextPosition(string $area): int
    {
        $max = $this->createQueryBuilder('w')
            ->select('MAX(w.position)')
            ->andWhere('w.area = :area')
            ->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : (int) $max + 1;
    }
}
