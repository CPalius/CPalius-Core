<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Fires from Doctrine prePersist (insert) and preUpdate. A listener may
 * reject() to abort the save — see RejectableEventTrait.
 */
final class EntityPreSaveEvent extends AbstractEntityLifecycleEvent
{
    use RejectableEventTrait;

    public function __construct(object $entity, string $entityTypeId, private readonly bool $isNew)
    {
        parent::__construct($entity, $entityTypeId);
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}
