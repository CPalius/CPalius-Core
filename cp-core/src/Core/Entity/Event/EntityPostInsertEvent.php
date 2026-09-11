<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Fires from Doctrine postPersist — the row already has its id. Pure
 * notification: rejecting here would be too late, there is no reject().
 */
final class EntityPostInsertEvent extends AbstractEntityLifecycleEvent
{
}
