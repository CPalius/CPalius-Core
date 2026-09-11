<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Node>
 */
class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    /**
     * Total node count for dashboard (excludes soft-deleted by default).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Node counts by type for dashboard stacked bar.
     *
     * @return list<array{type: string, count: int}>
     */
    public function countGroupedByType(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.type AS type, COUNT(n.id) AS count')
            ->andWhere('n.deletedAt IS NULL')
            ->groupBy('n.type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['type' => $row['type'], 'count' => (int) $row['count']], $rows);
    }

    /**
     * Node counts by status for dashboard doughnut.
     *
     * @return list<array{status: string, count: int}>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS count')
            ->groupBy('n.status')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['status' => $row['status'], 'count' => (int) $row['count']], $rows);
    }

    /**
     * Studio command desk: last editor activity, newest first.
     */
    public function createRecentlyUpdatedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.author', 'author')
            ->addSelect('author')
            ->andWhere('n.deletedAt IS NULL')
            ->orderBy('n.updatedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC');
    }

    /**
     * Batch-load nodes by id for FrontMenuRuntime (no status filter; caller skips missing).
     *
     * @param list<int> $nodeIds
     *
     * @return array<int, Node>
     */
    public function findByIdsIndexed(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $nodes = $this->createQueryBuilder('n')
            ->andWhere('n.id IN (:ids)')
            ->setParameter('ids', $nodeIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($nodes as $node) {
            $indexed[$node->getId()] = $node;
        }

        return $indexed;
    }

    /**
     * Whether slug+locale is taken (SlugGenerator uniqueness check).
     */
    public function slugExists(string $slug, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere('n.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * One published node by slug and locale (front-end page resolver).
     */
    public function findOnePublishedBySlugAndLocale(string $slug, string $locale): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Published node by slug in any locale (locale switch fallback).
     */
    public function findOnePublishedBySlug(string $slug, ?string $type = null): ?Node
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1);

        if ($type !== null && $type !== '') {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * All translation siblings sharing translation_group_id.
     *
     * @return list<Node>
     */
    public function findTranslations(Uuid $translationGroupId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.translationGroupId = :groupId')
            ->setParameter('groupId', $translationGroupId, 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * One translation in the group for target locale (language switcher).
     */
    public function findTranslation(Uuid $translationGroupId, string $targetLocale): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.translationGroupId = :groupId')
            ->andWhere('n.locale = :locale')
            ->setParameter('groupId', $translationGroupId, 'uuid')
            ->setParameter('locale', $targetLocale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Published nodes by type and locale, newest first.
     *
     * @return list<Node>
     */
    public function findPublishedByTypeAndLocale(string $type, string $locale, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Scheduled nodes due for publish (all content types).
     *
     * @return list<Node>
     */
    public function findDueScheduledNodes(?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.status = :status')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->andWhere('n.publishedAt <= :now')
            ->setParameter('status', Node::STATUS_SCHEDULED)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Count of STATUS_SCHEDULED nodes; optional $type filter for module widgets.
     */
    public function countPendingScheduledNodes(?string $type = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.status = :status')
            ->setParameter('status', Node::STATUS_SCHEDULED);

        if ($type !== null) {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Paginator-ready published list by type/locale (no joins).
     */
    public function createPublishedByTypeAndLocaleQueryBuilder(string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Published nodes in a category (many-to-many join; use Paginator fetchJoinCollection).
     */
    public function createPublishedByCategoryQueryBuilder(int $categoryId, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->innerJoin('n.categories', 'c')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('c.id = :categoryId')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('categoryId', $categoryId)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Published nodes by tag slug (same join/count pattern as category query).
     */
    public function createPublishedByTagQueryBuilder(string $tagSlug, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->innerJoin('n.tags', 't')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('t.slug = :slug')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('slug', $tagSlug)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Archive year/month groups in PHP (avoids non-portable DQL YEAR()/MONTH()).
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    public function findPublishedArchiveGroups(string $type, string $locale): array
    {
        $publishedDates = $this->createQueryBuilder('n')
            ->select('n.publishedAt AS publishedAt')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($publishedDates as $row) {
            /** @var \DateTimeImmutable $publishedAt */
            $publishedAt = $row['publishedAt'];
            $key = $publishedAt->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        krsort($counts);

        $groups = [];
        foreach ($counts as $key => $count) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $groups[] = ['year' => $year, 'month' => $month, 'count' => $count];
        }

        return $groups;
    }

    /**
     * Paginator query for one archive month (BETWEEN range computed in PHP).
     */
    public function createPublishedByDateRangeQueryBuilder(string $type, string $locale, int $year, int $month): QueryBuilder
    {
        $rangeStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $rangeEnd = $rangeStart->modify('first day of next month');

        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.publishedAt >= :rangeStart')
            ->andWhere('n.publishedAt < :rangeEnd')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('rangeStart', $rangeStart)
            ->setParameter('rangeEnd', $rangeEnd)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Title-only LIKE search (Law 6.3: no unindexed JSON scan).
     */
    public function createSearchQueryBuilder(string $searchTerm, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.title LIKE :term')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('term', '%'.addcslashes($searchTerm, '%_').'%')
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Related published posts by primary category (excludes self; empty if uncategorized).
     *
     * @return list<Node>
     */
    public function findRelatedPosts(Node $post, int $limit = 3): array
    {
        $category = $post->getCategory();
        if ($category === null) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.category = :categoryId')
            ->andWhere('n.id != :excludeId')
            ->setParameter('type', $post->getType())
            ->setParameter('locale', $post->getLocale())
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('categoryId', $category->getId())
            ->setParameter('excludeId', $post->getId())
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Manifesto 3.3: filter by flat NodeFieldIndex column with SQL operators.
     *
     * @return list<Node>
     */
    public function findByIndexedField(
        string $type,
        string $locale,
        string $fieldName,
        mixed $value,
        string $valueColumn = 'valueString',
        string $operator = '=',
        int $limit = 20,
        int $offset = 0,
    ): array {
        $allowedColumns = ['valueString', 'valueInt', 'valueDecimal', 'valueDatetime'];
        if (!\in_array($valueColumn, $allowedColumns, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid valueColumn: "%s"', $valueColumn));
        }

        $allowedOperators = ['=', '!=', '<', '<=', '>', '>='];
        if (!\in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid operator: "%s"', $operator));
        }

        return $this->createQueryBuilder('n')
            ->innerJoin(NodeFieldIndex::class, 'idx', 'WITH', 'idx.node = n.id')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('idx.fieldName = :fieldName')
            ->andWhere(sprintf('idx.%s %s :value', $valueColumn, $operator))
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('fieldName', $fieldName)
            ->setParameter('value', $value)
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * ProcessWire-style selector string query (fixed columns, modifiers, flat index fields).
     * Invalid segments are skipped fail-safe.
     *
     * @deprecated use App\Core\Entity\Query\CpEntityQueryFactory::fromSelector()
     *             which adds OR groups, access checks and shared column resolution
     *
     * @return list<Node>
     */
    public function findNodesBySelector(string $selector): array
    {
        $qb = $this->createQueryBuilder('n');

        $limit = 20;
        $offset = 0;
        $sortField = 'createdAt';
        $sortDirection = 'DESC';
        $joinedIndex = false;
        $conditionIndex = 0;

        foreach ($this->parseSelectorParts($selector) as [$key, $operator, $rawValue]) {
            switch ($key) {
                case 'limit':
                    $limit = max(0, (int) $rawValue);
                    break;

                case 'offset':
                    $offset = max(0, (int) $rawValue);
                    break;

                case 'sort':
                    [$sortField, $sortDirection] = $this->parseSort($rawValue);
                    break;

                case 'type':
                case 'status':
                case 'locale':
                case 'slug':
                    $paramName = sprintf('p%d', $conditionIndex++);
                    $qb->andWhere(sprintf('n.%s %s :%s', $key, $operator, $paramName))
                        ->setParameter($paramName, $rawValue);
                    break;

                case 'category':
                    $paramName = sprintf('p%d', $conditionIndex++);
                    $qb->andWhere(sprintf('n.category %s :%s', $operator, $paramName))
                        ->setParameter($paramName, (int) $rawValue);
                    break;

                default:
                    // Unknown key: treat as flat-indexed dynamic field.
                    if (!$joinedIndex) {
                        $qb->innerJoin(NodeFieldIndex::class, 'idx', 'WITH', 'idx.node = n.id');
                        $joinedIndex = true;
                    }

                    $fieldParam = sprintf('fname%d', $conditionIndex);
                    $valueParam = sprintf('fval%d', $conditionIndex);
                    ++$conditionIndex;

                    $valueColumn = $this->guessValueColumn($rawValue);

                    $qb->andWhere(sprintf('idx.fieldName = :%s', $fieldParam))
                        ->andWhere(sprintf('idx.%s %s :%s', $valueColumn, $operator, $valueParam))
                        ->setParameter($fieldParam, $key)
                        ->setParameter($valueParam, $this->castIndexValue($rawValue, $valueColumn));
                    break;
            }
        }

        $allowedSortFields = ['createdAt', 'updatedAt', 'publishedAt', 'title', 'slug'];
        if (!\in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'createdAt';
        }

        $qb->orderBy('n.'.$sortField, $sortDirection)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /**
     * Parse selector string into [key, operator, value] triples.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function parseSelectorParts(string $selector): array
    {
        // Try longer operators first so != and <= are not split incorrectly.
        $operators = ['!=', '<=', '>=', '=', '<', '>'];

        $parts = [];

        foreach (explode(',', $selector) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            foreach ($operators as $operator) {
                $pos = strpos($segment, $operator);
                if ($pos === false) {
                    continue;
                }

                $key = trim(substr($segment, 0, $pos));
                $value = trim(substr($segment, $pos + \strlen($operator)));

                if ($key === '') {
                    continue 2;
                }

                $parts[] = [$key, $operator, $value];
                continue 2;
            }

            // No operator: skip segment fail-safe.
        }

        return $parts;
    }

    /**
     * Parse sort token (snake_case field + optional ASC/DESC).
     *
     * @return array{0: string, 1: string}
     */
    private function parseSort(string $rawValue): array
    {
        $pieces = explode(':', $rawValue, 2);
        $field = trim($pieces[0]);
        $direction = isset($pieces[1]) ? strtoupper(trim($pieces[1])) : 'DESC';

        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $camelField = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));

        return [$camelField !== '' ? $camelField : 'createdAt', $direction];
    }

    /**
     * Guess NodeFieldIndex value column from selector value shape.
     */
    private function guessValueColumn(string $rawValue): string
    {
        if (preg_match('/^-?\d+$/', $rawValue) === 1) {
            return 'valueInt';
        }

        if (preg_match('/^-?\d+\.\d+$/', $rawValue) === 1) {
            return 'valueDecimal';
        }

        return 'valueString';
    }

    private function castIndexValue(string $rawValue, string $valueColumn): int|string
    {
        return match ($valueColumn) {
            'valueInt' => (int) $rawValue,
            default => $rawValue,
        };
    }
}
