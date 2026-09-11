<?php

declare(strict_types=1);

namespace App\Core\Entity\Query;

use App\Core\Content\QueryableFieldsRegistry;
use App\Core\Security\QueryScopeApplier;
use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use Doctrine\ORM\Query\Expr\Composite;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Fluent, access-aware query over content entities. v1 targets Node (columns +
 * the NodeFieldIndex flat index); the API is entity-agnostic so Resource and
 * taxonomy targets can be added without changing callers (T1.3+).
 *
 * One instance per query — built by CpEntityQueryFactory, never a service.
 *
 *   $factory->forBundle('post')
 *       ->where('status', '=', Node::STATUS_PUBLISHED)
 *       ->whereField('featured', '=', true)
 *       ->orX(fn (CpEntityQuery $q) => $q->where('slug', '=', 'x')->where('slug', '=', 'y'))
 *       ->accessCheck('node.post.view')
 *       ->sort('publishedAt', 'DESC')
 *       ->range(0, 20)
 *       ->getResult();
 */
final class CpEntityQuery
{
    private const ROOT_ALIAS = 'n';

    /** Public condition name => DQL expression on the root alias. */
    private const COLUMNS = [
        'id' => 'n.id',
        'type' => 'n.type',
        'status' => 'n.status',
        'moderation_state' => 'n.moderationState',
        'moderationState' => 'n.moderationState',
        'locale' => 'n.locale',
        'slug' => 'n.slug',
        'title' => 'n.title',
        'translation_group_id' => 'n.translationGroupId',
        'published_at' => 'n.publishedAt',
        'publishedAt' => 'n.publishedAt',
        'created_at' => 'n.createdAt',
        'createdAt' => 'n.createdAt',
        'updated_at' => 'n.updatedAt',
        'updatedAt' => 'n.updatedAt',
        'author' => 'IDENTITY(n.author)',
        'category' => 'IDENTITY(n.category)',
    ];

    private const SORTABLE = ['id', 'title', 'slug', 'status', 'createdAt', 'updatedAt', 'publishedAt'];

    private const SCALAR_OPERATORS = ['=' => '=', '!=' => '!=', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=', 'like' => 'LIKE'];

    private readonly QueryBuilder $qb;
    private readonly Composite $rootAnd;
    private ?Composite $group = null;
    private int $paramSeq = 0;
    private int $joinSeq = 0;
    private ?string $accessCapability = null;
    private string $accessOwnerField = 'author';
    private ?int $offset = null;
    private ?int $limit = null;
    /** @var list<array{0: string, 1: string}> */
    private array $orderBy = [];
    private bool $built = false;

    public function __construct(
        QueryBuilder $qb,
        private readonly QueryableFieldsRegistry $queryableFields,
        private readonly QueryScopeApplier $scopeApplier,
        private readonly ?string $bundle,
    ) {
        $this->qb = $qb;
        $this->rootAnd = $qb->expr()->andX();

        if ($this->bundle !== null) {
            $this->rootAnd->add(sprintf('%s.type = :cpq_bundle', self::ROOT_ALIAS));
            $qb->setParameter('cpq_bundle', $this->bundle);
        }
    }

    /**
     * Compare a real entity column. $value of null with '=' / '!=' maps to IS (NOT) NULL.
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $dql = $this->resolveColumn($column);
        $operator = strtolower(trim($operator));

        if ($value === null && \in_array($operator, ['=', '!='], true)) {
            $this->target()->add($dql.($operator === '=' ? ' IS NULL' : ' IS NOT NULL'));

            return $this;
        }

        if ($operator === 'in' || $operator === 'not in') {
            $param = $this->param();
            $this->target()->add(sprintf('%s %s (:%s)', $dql, $operator === 'in' ? 'IN' : 'NOT IN', $param));
            $this->qb->setParameter($param, \is_array($value) ? array_values($value) : [$value]);

            return $this;
        }

        if (!isset(self::SCALAR_OPERATORS[$operator])) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $param = $this->param();
        $this->target()->add(sprintf('%s %s :%s', $dql, self::SCALAR_OPERATORS[$operator], $param));
        $this->qb->setParameter($param, $value);

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->target()->add($this->resolveColumn($column).' IS NULL');

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->target()->add($this->resolveColumn($column).' IS NOT NULL');

        return $this;
    }

    /**
     * Filter on a queryable (flat-indexed) Node::data field. Always AND-ed at the
     * top level — calling it inside orX()/andX() is rejected because the inner
     * join would silently filter the whole result.
     */
    public function whereField(string $name, string $operator, mixed $value): self
    {
        if ($this->group !== null) {
            throw new \LogicException('whereField() cannot be used inside orX()/andX() in this version.');
        }
        if ($this->bundle === null) {
            throw new \LogicException('whereField() needs a bundle — use forBundle().');
        }

        $indexType = $this->queryableFields->getFieldsForType($this->bundle)[$name] ?? null;
        if ($indexType === null) {
            throw new \InvalidArgumentException(sprintf('Field "%s" is not queryable for bundle "%s".', $name, $this->bundle));
        }

        $operator = strtolower(trim($operator));
        if (!isset(self::SCALAR_OPERATORS[$operator])) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s" for field "%s".', $operator, $name));
        }

        $alias = 'cpqf'.$this->joinSeq++;
        $nameParam = $alias.'_n';
        $this->qb->innerJoin(
            NodeFieldIndex::class,
            $alias,
            Join::WITH,
            sprintf('%s.node = %s.id AND %s.fieldName = :%s', $alias, self::ROOT_ALIAS, $alias, $nameParam),
        );
        $this->qb->setParameter($nameParam, $name);

        $valueColumn = match ($indexType) {
            QueryableFieldsRegistry::TYPE_INT => 'valueInt',
            QueryableFieldsRegistry::TYPE_DECIMAL => 'valueDecimal',
            QueryableFieldsRegistry::TYPE_DATETIME => 'valueDatetime',
            default => 'valueString',
        };

        $param = $this->param();
        $this->rootAnd->add(sprintf('%s.%s %s :%s', $alias, $valueColumn, self::SCALAR_OPERATORS[$operator], $param));
        $this->qb->setParameter($param, $this->coerceIndexValue($indexType, $value));

        return $this;
    }

    /**
     * ( a OR b OR ... ) — the callback receives this query; each where() inside
     * it joins the OR group instead of the root AND.
     *
     * @param callable(self): void $build
     */
    public function orX(callable $build): self
    {
        return $this->nest($this->qb->expr()->orX(), $build);
    }

    /**
     * @param callable(self): void $build
     */
    public function andX(callable $build): self
    {
        return $this->nest($this->qb->expr()->andX(), $build);
    }

    public function accessCheck(string $capabilityBase, string $ownerField = 'author'): self
    {
        $this->accessCapability = $capabilityBase;
        $this->accessOwnerField = $ownerField;

        return $this;
    }

    public function sort(string $field, string $direction = 'DESC'): self
    {
        if (!\in_array($field, self::SORTABLE, true)) {
            throw new \InvalidArgumentException(sprintf('Cannot sort by "%s".', $field));
        }
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $this->orderBy[] = [self::ROOT_ALIAS.'.'.$field, $direction];

        return $this;
    }

    public function range(?int $offset, ?int $limit): self
    {
        $this->offset = $offset !== null ? max(0, $offset) : null;
        $this->limit = $limit !== null ? max(0, $limit) : null;

        return $this;
    }

    /**
     * @return list<Node>
     */
    public function getResult(): array
    {
        /** @var list<Node> $result */
        $result = $this->build()->getQuery()->getResult();

        return $result;
    }

    public function getOneOrNull(): ?Node
    {
        /** @var Node|null $result */
        $result = (clone $this->build())->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $result;
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        $qb = clone $this->build();
        $qb->select(self::ROOT_ALIAS.'.id')->distinct();

        return array_map(static fn (array $row): int => (int) $row['id'], $qb->getQuery()->getArrayResult());
    }

    public function count(): int
    {
        $qb = clone $this->build();
        $qb->select('COUNT(DISTINCT '.self::ROOT_ALIAS.'.id)')
            ->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Escape hatch for callers that need a raw QueryBuilder (further joins, custom select).
     */
    public function toQueryBuilder(): QueryBuilder
    {
        return $this->build();
    }

    private function build(): QueryBuilder
    {
        if ($this->built) {
            return $this->qb;
        }

        if ($this->rootAnd->count() > 0) {
            $this->qb->andWhere($this->rootAnd);
        }

        if ($this->accessCapability !== null) {
            $this->scopeApplier->apply($this->qb, self::ROOT_ALIAS, $this->accessCapability, $this->accessOwnerField);
        }

        foreach ($this->orderBy as [$field, $direction]) {
            $this->qb->addOrderBy($field, $direction);
        }

        if ($this->offset !== null) {
            $this->qb->setFirstResult($this->offset);
        }
        if ($this->limit !== null) {
            $this->qb->setMaxResults($this->limit);
        }

        $this->built = true;

        return $this->qb;
    }

    /**
     * @param callable(self): void $build
     */
    private function nest(Composite $composite, callable $build): self
    {
        if ($this->group !== null) {
            throw new \LogicException('orX()/andX() cannot be nested in this version.');
        }

        $this->group = $composite;
        $build($this);
        $this->group = null;

        if ($composite->count() > 0) {
            $this->rootAnd->add($composite);
        }

        return $this;
    }

    private function target(): Composite
    {
        return $this->group ?? $this->rootAnd;
    }

    private function resolveColumn(string $column): string
    {
        return self::COLUMNS[$column]
            ?? throw new \InvalidArgumentException(sprintf('Unknown queryable column "%s".', $column));
    }

    private function param(): string
    {
        return 'cpq_p'.$this->paramSeq++;
    }

    private function coerceIndexValue(string $indexType, mixed $value): mixed
    {
        return match ($indexType) {
            QueryableFieldsRegistry::TYPE_INT => (int) $value,
            QueryableFieldsRegistry::TYPE_DECIMAL => (string) $value,
            QueryableFieldsRegistry::TYPE_DATETIME => $value instanceof \DateTimeInterface
                ? \DateTime::createFromInterface($value)
                : new \DateTime((string) $value),
            default => (string) $value,
        };
    }
}
