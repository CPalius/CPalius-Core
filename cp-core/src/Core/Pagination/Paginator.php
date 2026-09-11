<?php

declare(strict_types=1);

namespace App\Core\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Lightweight pagination wrapper around Doctrine ORM Paginator (no KnpPaginatorBundle).
 * Uses Doctrine's fetch-join-aware COUNT for correct totals with one-to-many joins.
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

        $query = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        $doctrinePaginator = new DoctrinePaginator($query, fetchJoinCollection: true);

        return new PaginatedResult(
            items: iterator_to_array($doctrinePaginator->getIterator()),
            totalItems: \count($doctrinePaginator),
            currentPage: $page,
            perPage: $perPage,
        );
    }
}
