<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Fires from Doctrine preRemove. A listener may reject() to abort the delete.
 */
final class EntityPreDeleteEvent extends AbstractEntityLifecycleEvent
{
    use RejectableEventTrait;
}
