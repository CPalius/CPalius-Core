<?php

declare(strict_types=1);

namespace App\Core\Pagination;

/**
 * Read-only DTO for one paginated page plus metadata (totalItems, currentPage, etc.).
 * Twig may iterate items directly; pagination partial reads meta fields.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class PaginatedResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items items on the current page (limit/offset already applied)
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalItems,
        private readonly int $currentPage,
        private readonly int $perPage,
    ) {
    }

    /**
     * @return list<T>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getTotalPages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->totalItems / $this->perPage) : 0;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getTotalPages();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
