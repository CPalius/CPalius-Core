<?php

declare(strict_types=1);

namespace App\Core\Taxonomy\Repository;

use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Term>
 */
class TermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Term::class);
    }

    /**
     * All terms of a vocabulary for one locale, ordered for tree building
     * (parent nulls first is not guaranteed by SQL — callers nest by parent id).
     *
     * @return list<Term>
     */
    public function findByVocabulary(Vocabulary $vocabulary, ?string $locale = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.vocabulary = :vocabulary')
            ->setParameter('vocabulary', $vocabulary)
            ->orderBy('t.weight', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if ($locale !== null) {
            $qb->andWhere('t.locale = :locale')->setParameter('locale', $locale);
        }

        /** @var list<Term> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function findOneBySlug(Vocabulary $vocabulary, string $slug, string $locale): ?Term
    {
        return $this->findOneBy(['vocabulary' => $vocabulary, 'slug' => $slug, 'locale' => $locale]);
    }

    /**
     * First term with this slug in any language.
     *
     * The slug is unique per (vocabulary, locale), not globally, so this can
     * legitimately match more than one row; ordered by locale so the answer is
     * at least stable between requests rather than left to the storage engine.
     */
    public function findOneBySlugAnyLocale(Vocabulary $vocabulary, string $slug): ?Term
    {
        return $this->findOneBy(
            ['vocabulary' => $vocabulary, 'slug' => $slug],
            ['locale' => 'ASC'],
        );
    }

    public function findOneByTranslationGroup(Uuid $translationGroupId, string $locale): ?Term
    {
        return $this->findOneBy(['translationGroupId' => $translationGroupId, 'locale' => $locale]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Term>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        /** @var list<Term> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countByVocabulary(Vocabulary $vocabulary): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.vocabulary = :vocabulary')
            ->setParameter('vocabulary', $vocabulary)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
