<?php

declare(strict_types=1);

namespace App\Core\Trash;

use App\Core\Database\Traits\SoftDeletableTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The recycle bin's second half: rows that were not DELETEd but marked deleted (posts, pages,
 * showcase items, forum topics). They never reach cp_trash_items, so without this the bin looked
 * nearly empty while most of the site's content lived in "deleted" state.
 *
 * Which entities count: those using SoftDeletableTrait (clearing deletedAt is a full restore) and
 * those a module vouches for with a SoftDeleteHandlerInterface (its own restore logic). Anything
 * else with a deletedAt column is left alone rather than restored half-way. Classes marked
 * #[NotTrashable] (private messages) never appear.
 *
 * A row is addressed as "<resource name>:<id>".
 */
final class SoftDeletedBin
{
    private const PER_TYPE = 100;

    /** @var array<string, class-string>|null */
    private ?array $classes = null;

    /**
     * @param iterable<SoftDeleteHandlerInterface> $handlers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrashPolicy $policy,
        #[AutowireIterator('cp.trash_soft_handler')]
        private readonly iterable $handlers = [],
    ) {
    }

    /**
     * @return array<string, class-string> resource name => entity class
     */
    public function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $found = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();
            if ($metadata->isMappedSuperclass || !$metadata->hasField('deletedAt') || !$this->policy->isTrashableClass($class)) {
                continue;
            }
            if (!$this->usesTrait($class) && $this->handlerFor($class) === null) {
                continue;
            }
            $found[$this->policy->resourceName($class)] = $class;
        }
        ksort($found);

        return $this->classes = $found;
    }

    /**
     * @return array<string, int> resource name => rows in the bin
     */
    public function counts(): array
    {
        $out = [];
        foreach ($this->classes() as $name => $class) {
            $count = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(e)')->from($class, 'e')->where('e.deletedAt IS NOT NULL')
                ->getQuery()->getSingleScalarResult();
            if ($count > 0) {
                $out[$name] = $count;
            }
        }

        return $out;
    }

    /**
     * Newest deletions first, at most PER_TYPE per type.
     *
     * @return list<array{key: string, type: string, detail: ?string, label: string, deletedAt: \DateTimeImmutable}>
     */
    public function items(?string $type = null, ?string $query = null): array
    {
        $rows = [];
        foreach ($this->classes() as $name => $class) {
            if ($type !== null && $type !== $name) {
                continue;
            }
            $entities = $this->entityManager->createQueryBuilder()
                ->select('e')->from($class, 'e')->where('e.deletedAt IS NOT NULL')
                ->orderBy('e.deletedAt', 'DESC')->setMaxResults(self::PER_TYPE)
                ->getQuery()->getResult();

            foreach ($entities as $entity) {
                $label = $this->label($entity, $name);
                if ($query !== null && stripos($label, $query) === false && !str_contains((string) $this->id($entity), $query)) {
                    continue;
                }
                $rows[] = [
                    'key' => $name.':'.$this->id($entity),
                    'type' => $name,
                    'detail' => method_exists($entity, 'getType') && \is_string($entity->getType()) ? $entity->getType() : null,
                    'label' => $label,
                    'deletedAt' => $entity->getDeletedAt(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['deletedAt'] <=> $a['deletedAt']);

        return $rows;
    }

    public function restore(string $key): string
    {
        $entity = $this->find($key);
        $handler = $this->handlerFor($entity::class);

        if ($handler !== null) {
            $handler->restore($entity);
        } else {
            $entity->restore();
            $this->entityManager->flush();
        }

        return $this->label($entity, $this->policy->resourceName($entity::class));
    }

    /** Removes the row for good — and does not drop a copy of it back into the bin. */
    public function purge(string $key): void
    {
        $entity = $this->find($key);
        $this->policy->withoutCapture(function () use ($entity): void {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        });
    }

    private function find(string $key): object
    {
        [$name, $id] = array_pad(explode(':', $key, 2), 2, '');
        $class = $this->classes()[$name] ?? null;
        $entity = $class !== null && $id !== '' ? $this->entityManager->find($class, $id) : null;
        if ($entity === null || $entity->getDeletedAt() === null) {
            throw new \RuntimeException('Recycle bin item not found.');
        }

        return $entity;
    }

    private function handlerFor(string $class): ?SoftDeleteHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof SoftDeleteHandlerInterface && $handler->supports($class)) {
                return $handler;
            }
        }

        return null;
    }

    private function usesTrait(string $class): bool
    {
        return \in_array(SoftDeletableTrait::class, $this->traitsOf($class), true);
    }

    /** @return list<string> every trait used by $class and its parents, nested traits included */
    private function traitsOf(string $class): array
    {
        $traits = [];
        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $c) {
            $stack = array_values(class_uses($c) ?: []);
            while ($stack !== []) {
                $trait = array_pop($stack);
                $traits[] = $trait;
                $stack = [...$stack, ...array_values(class_uses($trait) ?: [])];
            }
        }

        return $traits;
    }

    private function id(object $entity): mixed
    {
        return method_exists($entity, 'getId') ? $entity->getId() : null;
    }

    private function label(object $entity, string $resource): string
    {
        foreach (['getTitle', 'getName', 'getSubject', 'getLabel', 'getSlug'] as $getter) {
            if (method_exists($entity, $getter)) {
                $value = $entity->{$getter}();
                if (\is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return $resource.' #'.$this->id($entity);
    }
}

