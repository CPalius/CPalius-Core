<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneBySlug(string $slug, string $locale): ?Category
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * AACP Dashboard "Kategoriler" kartı için toplam sayı (tüm diller).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Locale için kök kategoriler + çocukları (tek sorguda eager).
     *
     * @return list<Category>
     */
    public function findTreeByLocale(string $locale): array
    {
        /** @var list<Category> $all */
        $all = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')
            ->addSelect('ch')
            ->andWhere('c.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('ch.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $all,
            static fn (Category $category): bool => $category->getParent() === null
        ));
    }

    /**
     * Yayınlanmış yazı sayısı (many-to-many), kategori id → adet.
     *
     * @return array<int, int>
     */
    public function countPublishedPostsByLocale(string $type, string $locale): array
    {
        /** @var list<array{id: int, cnt: string|int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id AS id, COUNT(DISTINCT n.id) AS cnt')
            ->from(Node::class, 'n')
            ->innerJoin('n.categories', 'c')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('c.locale = :locale')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->groupBy('c.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
