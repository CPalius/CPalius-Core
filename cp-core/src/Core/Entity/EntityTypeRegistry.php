<?php

declare(strict_types=1);

namespace App\Core\Entity;

/**
 * Compile-time collected #[CpEntityType] declarations (core + modules), addressed
 * by id. Filled by EntityTypeRegistrationPass; no runtime scanning.
 *
 * A missing entity type is not an error here — callers decide whether an unknown
 * id means "deny" or "not fieldable".
 */
final class EntityTypeRegistry
{
    /** @var array<string, EntityTypeDefinition> */
    private array $definitions;

    /**
     * @param array<string, array<string, mixed>> $rawDefinitions id => scalar payload
     */
    public function __construct(array $rawDefinitions)
    {
        $definitions = [];
        foreach ($rawDefinitions as $raw) {
            $definition = EntityTypeDefinition::fromArray($raw);
            if ($definition->id !== '') {
                $definitions[$definition->id] = $definition;
            }
        }
        ksort($definitions);

        $this->definitions = $definitions;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function get(string $id): EntityTypeDefinition
    {
        return $this->definitions[$id]
            ?? throw new \InvalidArgumentException(sprintf('Unknown entity type "%s".', $id));
    }

    public function tryGet(string $id): ?EntityTypeDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /**
     * @return array<string, EntityTypeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, EntityTypeDefinition>
     */
    public function fieldable(): array
    {
        return array_filter($this->definitions, static fn (EntityTypeDefinition $d): bool => $d->fieldable);
    }

    public function forClass(string $className): ?EntityTypeDefinition
    {
        $className = ltrim($className, '\\');
        foreach ($this->definitions as $definition) {
            if ($definition->className === $className) {
                return $definition;
            }
        }

        return null;
    }
}
