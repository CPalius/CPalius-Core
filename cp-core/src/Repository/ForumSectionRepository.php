<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumSection>
 */
final class ForumSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumSection::class);
    }

    public function findOneBySlugAndLocale(string $slug, string $locale): ?ForumSection
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * @return list<ForumSection>
     */
    public function findRootSectionsByLocale(string $locale): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.locale = :locale')
            ->andWhere('s.parent IS NULL')
            ->setParameter('locale', $locale)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ForumSection>
     */
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.parent', 'p')->addSelect('p')
            ->andWhere('s.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countDirectChildren(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.parent = :parent')
            ->setParameter('parent', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    public function findDirectChildTitles(ForumSection $section): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.title')
            ->andWhere('s.parent = :parent')
            ->setParameter('parent', $section)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (string) $row['title'], $rows);
    }
}
