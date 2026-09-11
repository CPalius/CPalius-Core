<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Entity\FieldableInterface;
use App\Core\Field\Entity\FieldDefinition;
use App\Entity\Node;

/**
 * Everything a field type needs to normalize / validate / render one value:
 * the definition, the active locale, and the owning entity (null when creating).
 *
 * The entity is a FieldableInterface, not a concrete class — the field layer is
 * entity-agnostic (Node, User, module records all flow through here identically).
 */
final class FieldContext
{
    public function __construct(
        public readonly FieldDefinition $definition,
        public readonly string $locale,
        public readonly ?FieldableInterface $entity = null,
    ) {
    }

    public function bundle(): string
    {
        return $this->definition->getBundle();
    }

    public function fieldName(): string
    {
        return $this->definition->getName();
    }

    /**
     * Back-compat convenience for field types that only ever run against nodes:
     * the owning entity when it is a Node, otherwise null.
     */
    public function node(): ?Node
    {
        return $this->entity instanceof Node ? $this->entity : null;
    }

    public function withDefinition(FieldDefinition $definition): self
    {
        return new self($definition, $this->locale, $this->entity);
    }
}
