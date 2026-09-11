<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

abstract class AbstractEntityLifecycleEvent implements EntityLifecycleEventInterface
{
    public function __construct(
        private readonly object $entity,
        private readonly string $entityTypeId,
    ) {
    }

    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }
}
