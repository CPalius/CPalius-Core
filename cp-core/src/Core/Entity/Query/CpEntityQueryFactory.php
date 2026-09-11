<?php

declare(strict_types=1);

namespace App\Core\Entity\Query;

use App\Core\Content\QueryableFieldsRegistry;
use App\Core\Entity\EntityTypeRegistry;
use App\Core\Security\QueryScopeApplier;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Entry point for CpEntityQuery. A service; hands out fresh (stateful) query
 * objects. v1 targets Node only — forEntityType() rejects other ids until the
 * flat index and column resolution are generalized (T1.3).
 */
final class CpEntityQueryFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueryableFieldsRegistry $queryableFields,
        private readonly QueryScopeApplier $scopeApplier,
        private readonly EntityTypeRegistry $entityTypes,
    ) {
    }

    /**
     * Every Node, regardless of bundle. whereField() is unavailable (no single bundle).
     */
    public function forNode(): CpEntityQuery
    {
        return $this->make(null);
    }

    /**
     * Nodes of one bundle (Node::type value, e.g. "post", "page").
     */
    public function forBundle(string $bundle): CpEntityQuery
    {
        return $this->make($bundle);
    }

    /**
     * Query an entity type by its #[CpEntityType] id. v1 accepts only "node".
     */
    public function forEntityType(string $entityTypeId): CpEntityQuery
    {
        $definition = $this->entityTypes->tryGet($entityTypeId);
        if ($definition === null || $definition->className !== Node::class) {
            throw new \InvalidArgumentException(sprintf('CpEntityQuery v1 supports only the "node" entity type, not "%s".', $entityTypeId));
        }

        return $this->make(null);
    }

    /**
     * ProcessWire-style selector string → CpEntityQuery. A leading "type=<bundle>"
     * sets the bundle so dynamic fields resolve against the flat index. Unknown
     * segments are skipped fail-safe. Default range is 20 rows when unspecified.
     *
     *   fromSelector('type=vehicle, price>50000, sort=-price, limit=10')
     */
    public function fromSelector(string $selector): CpEntityQuery
    {
        $parts = $this->parseSelector($selector);

        $bundle = null;
        foreach ($parts as [$key, $operator, $value]) {
            if ($key === 'type' && $operator === '=' && $value !== '') {
                $bundle = $value;
            }
        }

        $query = $this->make($bundle);
        $limit = null;
        $offset = null;

        foreach ($parts as [$key, $operator, $value]) {
            switch ($key) {
                case 'limit':
                    $limit = max(0, (int) $value);
                    break;

                case 'offset':
                    $offset = max(0, (int) $value);
                    break;

                case 'sort':
                    [$field, $direction] = $this->parseSort($value);
                    try {
                        $query->sort($field, $direction);
                    } catch (\InvalidArgumentException) {
                        // Not a sortable column — ignore.
                    }
                    break;

                case 'type':
                    if ($bundle !== null && $operator === '=') {
                        break; // already applied by make()
                    }
                    // no break
                case 'status':
                case 'locale':
                case 'slug':
                case 'title':
                    $this->tryWhere($query, $key, $operator, $value);
                    break;

                case 'category':
                    $this->tryWhere($query, 'category', $operator, (int) $value);
                    break;

                default:
                    if ($bundle !== null) {
                        try {
                            $query->whereField($key, $operator, $value);
                        } catch (\Throwable) {
                            // Unknown / non-queryable field — skip fail-safe.
                        }
                    }
            }
        }

        return $query->range($offset, $limit ?? 20);
    }

    private function make(?string $bundle): CpEntityQuery
    {
        $qb = $this->entityManager->getRepository(Node::class)->createQueryBuilder('n');

        return new CpEntityQuery($qb, $this->queryableFields, $this->scopeApplier, $bundle);
    }

    private function tryWhere(CpEntityQuery $query, string $column, string $operator, mixed $value): void
    {
        try {
            $query->where($column, $operator, $value);
        } catch (\InvalidArgumentException) {
            // Unknown column / operator — skip fail-safe.
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> key, operator, value
     */
    private function parseSelector(string $selector): array
    {
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
                if ($key === '') {
                    continue 2;
                }

                $parts[] = [$key, $operator, trim(substr($segment, $pos + \strlen($operator)))];
                continue 2;
            }
        }

        return $parts;
    }

    /**
     * "-price" → DESC price · "price:desc" → DESC price · "created" → ASC createdAt.
     *
     * @return array{0: string, 1: string}
     */
    private function parseSort(string $raw): array
    {
        $raw = trim($raw);
        $direction = 'ASC';

        if (str_starts_with($raw, '-')) {
            $direction = 'DESC';
            $raw = substr($raw, 1);
        } elseif (str_contains($raw, ':')) {
            [$raw, $dir] = explode(':', $raw, 2);
            $direction = strtoupper(trim($dir)) === 'DESC' ? 'DESC' : 'ASC';
        }

        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', trim($raw)))));

        return [$camel !== '' ? $camel : 'createdAt', $direction];
    }
}
