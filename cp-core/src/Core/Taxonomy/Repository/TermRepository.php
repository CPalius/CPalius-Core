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

    /**
     * Roots of a vocabulary for one locale, children already loaded.
     *
     * The blog category strip walks root.children. Loading the terms first
     * and then asking each root for its children is an N+1 on cp_terms —
     * eleven roots trip Law 6.1. One JOIN FETCH initialises the collection.
     *
     * @return list<Term>
     */
    public function findTreeByVocabulary(Vocabulary $vocabulary, string $locale): array
    {
        /** @var list<Term> $rows */
        $rows = $this->createQueryBuilder('t')
            ->addSelect('p', 'c')
            ->leftJoin('t.parent', 'p')
            ->leftJoin('t.children', 'c')
            ->andWhere('t.vocabulary = :vocabulary')
            ->andWhere('t.locale = :locale')
            ->setParameter('vocabulary', $vocabulary)
            ->setParameter('locale', $locale)
            ->orderBy('t.weight', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->addOrderBy('c.weight', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $unique = [];
        foreach ($rows as $term) {
            $id = $term->getId();
            if ($id !== null) {
                $unique[$id] = $term;
            }
        }

        return array_values(array_filter(
            $unique,
            static fn (Term $term): bool => $term->getParent() === null,
        ));
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
