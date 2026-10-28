<?php

declare(strict_types=1);

namespace App\Core\Pagination;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Lightweight pagination wrapper around Doctrine ORM Paginator (no KnpPaginatorBundle).
 *
 * Doctrine's Paginator has two modes. With fetchJoinCollection it protects against a one-to-many join
 * multiplying rows: one query for the total (wrapped in a subselect), one for the page's ids (wrapped in
 * two derived tables, so MySQL cannot stop at LIMIT: it builds and sorts the whole filtered set first), and
 * a third to load the entities. Without it, a list is just the query with LIMIT and a plain COUNT.
 *
 * Almost every list here has no to-many join, and the heavy mode cost ~20 ms per list at only 5 000 rows
 * (measured), growing with the table. So the heavy mode is used only when the query needs it: a join to a
 * collection-valued association, GROUP BY / HAVING, or anything this cannot classify with certainty.
 */
final class Paginator
{
    private const DEFAULT_PER_PAGE = 12;

    /**
     * @template T
     *
     * @param QueryBuilder $queryBuilder queryBuilder with ORDER BY; limit/offset applied here
     *
     * @return PaginatedResult<T>
     */
    public function paginate(QueryBuilder $queryBuilder, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): PaginatedResult
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $needsCollectionAwareMode = self::multipliesRows($queryBuilder);

        $query = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        $doctrinePaginator = new DoctrinePaginator($query, fetchJoinCollection: $needsCollectionAwareMode);
        // The simple mode also needs the output walkers off: they are what wrap the query in derived tables.
        if (!$needsCollectionAwareMode) {
            $doctrinePaginator->setUseOutputWalkers(false);
        }

        return new PaginatedResult(
            items: iterator_to_array($doctrinePaginator->getIterator()),
            totalItems: \count($doctrinePaginator),
            currentPage: $page,
            perPage: $perPage,
        );
    }

    /**
     * True when one row of the main entity can appear more than once in the result, or when that cannot
     * be ruled out. Only joins along to-one associations are known to be safe.
     */
    public static function multipliesRows(QueryBuilder $queryBuilder): bool
    {
        if ($queryBuilder->getDQLPart('groupBy') !== [] || $queryBuilder->getDQLPart('having') !== null) {
            return true;
        }

        $em = $queryBuilder->getEntityManager();
        $classOf = [];
        $roots = $queryBuilder->getRootAliases();
        $entities = $queryBuilder->getRootEntities();
        foreach ($roots as $i => $alias) {
            if (!isset($entities[$i])) {
                return true;
            }
            $classOf[$alias] = $entities[$i];
        }

        foreach ($queryBuilder->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                if (!$join instanceof Join) {
                    return true;
                }
                $path = explode('.', (string) $join->getJoin());
                if (\count($path) !== 2 || !isset($classOf[$path[0]])) {
                    return true; // a class join or a path through something this does not track
                }

                $metadata = $em->getClassMetadata($classOf[$path[0]]);
                if (!$metadata->hasAssociation($path[1]) || $metadata->isCollectionValuedAssociation($path[1])) {
                    return true;
                }
                $classOf[(string) $join->getAlias()] = $metadata->getAssociationTargetClass($path[1]);
            }
        }

        return false;
    }
}
