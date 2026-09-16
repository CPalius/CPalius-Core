<?php

declare(strict_types=1);

namespace Modules\Showcase\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseItemIndex;
use Modules\Showcase\Entity\ShowcaseItemMedia;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Query\ShowcaseFilter;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ShowcaseItem>
 */
final class ShowcaseItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShowcaseItem::class);
    }

    /**
     * The single listing query. Every screen — public grid, member dashboard,
     * moderation queue, API — goes through here, so access rules and the
     * soft-delete filter cannot be forgotten on one of them.
     *
     * Custom-field criteria become one INNER JOIN on the flat index per field
     * (Law 6.3), each with its own alias, instead of a JSON scan.
     */
    public function createFilteredQueryBuilder(ShowcaseFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->innerJoin('i.type', 't')->addSelect('t')
            ->leftJoin('t.translations', 'ttr')->addSelect('ttr')
            ->leftJoin('i.owner', 'o')->addSelect('o');

        if (!$filter->includeTrashed) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        if (!$filter->anyLocale) {
            $qb->andWhere('i.locale = :locale')->setParameter('locale', $filter->locale);
        }

        if ($filter->statuses !== []) {
            $qb->andWhere('i.status IN (:statuses)')->setParameter('statuses', $filter->statuses);
        }

        if (!$filter->includeExpired) {
            $qb->andWhere('i.expiresAt IS NULL OR i.expiresAt > :now')
                ->setParameter('now', new \DateTimeImmutable());
        }

        if ($filter->type instanceof ShowcaseType) {
            $qb->andWhere('i.type = :type')->setParameter('type', $filter->type);
        }

        if ($filter->ownerId !== null) {
            $qb->andWhere('IDENTITY(i.owner) = :ownerId')->setParameter('ownerId', $filter->ownerId);
        }

        if ($filter->featuredOnly) {
            $qb->andWhere('i.featured = true');
        }

        if ($filter->termId !== null) {
            // EXISTS instead of a join: an item in two matching categories must
            // not appear twice in the grid, and DISTINCT would defeat the index.
            $qb->andWhere(sprintf(
                'EXISTS (SELECT sub.id FROM %s sub INNER JOIN sub.terms subterm WHERE sub.id = i.id AND subterm.id = :termId)',
                ShowcaseItem::class,
            ))->setParameter('termId', $filter->termId);
        }

        if ($filter->search !== null && $filter->search !== '') {
            $qb->andWhere('i.title LIKE :search OR i.summary LIKE :search')
                ->setParameter('search', '%'.$this->escapeLike($filter->search).'%');
        }

        if ($filter->minPrice !== null) {
            $qb->andWhere('i.price IS NOT NULL AND i.price >= :minPrice')->setParameter('minPrice', $filter->minPrice);
        }

        if ($filter->maxPrice !== null) {
            $qb->andWhere('i.price IS NOT NULL AND i.price <= :maxPrice')->setParameter('maxPrice', $filter->maxPrice);
        }

        $this->applyFieldCriteria($qb, $filter);

        return $this->applySort($qb, $filter->sort);
    }

    /**
     * Published item in one locale. Returns null rather than throwing so the
     * controller can offer a cross-locale sibling instead of a bare 404.
     */
    public function findOneVisibleBySlug(string $slug, string $locale): ?ShowcaseItem
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.type', 't')->addSelect('t')
            ->leftJoin('t.translations', 'ttr')->addSelect('ttr')
            ->leftJoin('i.owner', 'o')->addSelect('o')
            ->andWhere('i.slug = :slug')
            ->andWhere('i.locale = :locale')
            ->andWhere('i.status = :status')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.expiresAt IS NULL OR i.expiresAt > :now')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->setParameter('status', ShowcaseItem::STATUS_PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Same slug in any locale — the fallback when a link was shared without its
     * locale prefix surviving the trip.
     */
    public function findOneBySlugAnyLocale(string $slug): ?ShowcaseItem
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.type', 't')->addSelect('t')
            ->andWhere('i.slug = :slug')
            ->andWhere('i.status = :status')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setParameter('status', ShowcaseItem::STATUS_PUBLISHED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTranslationGroup(Uuid $groupId, string $locale): ?ShowcaseItem
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.type', 't')->addSelect('t')
            ->andWhere('i.translationGroupId = :groupId')
            ->andWhere('i.locale = :locale')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('groupId', $groupId, 'uuid')
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ShowcaseItem>
     */
    public function findTranslationSiblings(ShowcaseItem $item): array
    {
        $groupId = $item->getTranslationGroupId();

        if (!$groupId instanceof Uuid) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->andWhere('i.translationGroupId = :groupId')
            ->andWhere('i.id != :id')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('groupId', $groupId, 'uuid')
            ->setParameter('id', $item->getId())
            ->getQuery()
            ->getResult();
    }

    public function slugExists(string $slug, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.slug = :slug')
            ->andWhere('i.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere('i.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return array<string, int> status => count, including statuses with no rows
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(ShowcaseItem::STATUSES, 0);

        /** @var list<array{status: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS total')
            ->andWhere('i.deletedAt IS NULL')
            ->groupBy('i.status')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForType(ShowcaseType $type): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOwnedBy(int $ownerId, string ...$statuses): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('IDENTITY(i.owner) = :ownerId')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('ownerId', $ownerId);

        if ($statuses !== []) {
            $qb->andWhere('i.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Visible items for widgets and cross-module blocks.
     *
     * @return list<ShowcaseItem>
     */
    public function findVisible(string $locale, int $limit, bool $featuredOnly = false, ?ShowcaseType $type = null): array
    {
        $qb = $this->createFilteredQueryBuilder(new ShowcaseFilter(
            locale: $locale,
            type: $type,
            featuredOnly: $featuredOnly,
        ));

        return $qb->setMaxResults(max(1, $limit))->getQuery()->getResult();
    }

    /**
     * Title/summary match for the global search provider.
     *
     * @return list<ShowcaseItem>
     */
    public function searchVisible(string $term, string $locale, int $limit): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        return $this->createFilteredQueryBuilder(new ShowcaseFilter(locale: $locale, search: $term))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * First gallery image of each item, in one query.
     *
     * Exists so a listing can resolve cover fallbacks without touching
     * $item->getMedia(): that collection is lazy, so reading it inside a loop
     * over a page of cards is exactly the N+1 the dev-mode guard exists to catch
     * (Law 6.1). Returns scalars, not entities — the caller only needs an id.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, int> item id => asset id
     */
    public function firstMediaAssetIds(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        /** @var list<array{itemId: int, assetId: int, weight: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.item) AS itemId, m.assetId AS assetId, m.weight AS weight')
            ->from(ShowcaseItemMedia::class, 'm')
            ->andWhere('m.item IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->orderBy('m.weight', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $first = [];

        foreach ($rows as $row) {
            // Ordered by weight, so the first row seen for an item is its first
            // slide; later rows are skipped.
            $first[(int) $row['itemId']] ??= (int) $row['assetId'];
        }

        return $first;
    }

    /**
     * Distinct non-empty values of one indexed field, for building filter
     * dropdowns without a second configuration screen.
     *
     * @return list<string>
     */
    public function distinctIndexedValues(ShowcaseType $type, string $fieldName, string $locale, int $limit = 100): array
    {
        /** @var list<array{value: string|null}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT x.valueString AS value')
            ->from(ShowcaseItemIndex::class, 'x')
            ->innerJoin(ShowcaseItem::class, 'i', 'WITH', 'x.item = i')
            ->andWhere('x.fieldName = :fieldName')
            ->andWhere('x.valueString IS NOT NULL')
            ->andWhere('i.type = :type')
            ->andWhere('i.locale = :locale')
            ->andWhere('i.status = :status')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('fieldName', $fieldName)
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', ShowcaseItem::STATUS_PUBLISHED)
            ->orderBy('x.valueString', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(
            array_map(static fn (array $row): string => (string) $row['value'], $rows),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function applyFieldCriteria(QueryBuilder $qb, ShowcaseFilter $filter): void
    {
        foreach (array_values($filter->fieldCriteria) as $i => $criterion) {
            $alias = 'fx'.$i;
            $param = 'fxv'.$i;
            $nameParam = 'fxn'.$i;

            $qb->innerJoin(
                ShowcaseItemIndex::class,
                $alias,
                'WITH',
                sprintf('%s.item = i AND %s.fieldName = :%s', $alias, $alias, $nameParam),
            );
            $qb->setParameter($nameParam, $criterion['field']);

            $column = match ($criterion['kind']) {
                'int' => $alias.'.valueInt',
                'decimal' => $alias.'.valueDecimal',
                'datetime' => $alias.'.valueDatetime',
                default => $alias.'.valueString',
            };

            // Operators are an allowlist, never raw input: ShowcaseListingFilterBuilder
            // maps request keys onto these, so no request value reaches DQL.
            switch ($criterion['op']) {
                case '>=':
                    $qb->andWhere(sprintf('%s >= :%s', $column, $param));
                    break;
                case '<=':
                    $qb->andWhere(sprintf('%s <= :%s', $column, $param));
                    break;
                case 'like':
                    $qb->andWhere(sprintf('%s LIKE :%s', $column, $param));
                    break;
                default:
                    $qb->andWhere(sprintf('%s = :%s', $column, $param));
            }

            $qb->setParameter($param, $criterion['value']);
        }
    }

    private function applySort(QueryBuilder $qb, string $sort): QueryBuilder
    {
        // Featured first on every sort but the explicit alphabetical one: a
        // promoted listing that sinks to page four is not promoted.
        if ($sort !== ShowcaseFilter::SORT_TITLE) {
            $qb->addOrderBy('i.featured', 'DESC');
        }

        return match ($sort) {
            ShowcaseFilter::SORT_OLDEST => $qb->addOrderBy('i.publishedAt', 'ASC')->addOrderBy('i.id', 'ASC'),
            ShowcaseFilter::SORT_PRICE_ASC => $qb->addOrderBy('i.price', 'ASC')->addOrderBy('i.id', 'DESC'),
            ShowcaseFilter::SORT_PRICE_DESC => $qb->addOrderBy('i.price', 'DESC')->addOrderBy('i.id', 'DESC'),
            ShowcaseFilter::SORT_POPULAR => $qb->addOrderBy('i.viewCount', 'DESC')->addOrderBy('i.id', 'DESC'),
            ShowcaseFilter::SORT_RATING => $qb->addOrderBy('i.ratingCount', 'DESC')->addOrderBy('i.ratingSum', 'DESC')->addOrderBy('i.id', 'DESC'),
            ShowcaseFilter::SORT_TITLE => $qb->addOrderBy('i.title', 'ASC'),
            default => $qb->addOrderBy('i.publishedAt', 'DESC')->addOrderBy('i.id', 'DESC'),
        };
    }

    /**
     * LIKE wildcards in user input would turn a search box into a table scan
     * pattern ("%%%"), so they are escaped rather than passed through.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
