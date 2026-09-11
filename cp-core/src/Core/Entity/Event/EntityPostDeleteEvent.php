<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Fires from Doctrine postRemove. The PHP object is still fully populated
 * (only the DB row is gone) — pure notification, no reject().
 */
final class EntityPostDeleteEvent extends AbstractEntityLifecycleEvent
{
}
