<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Fires from Doctrine postUpdate. Pure notification — no reject().
 */
final class EntityPostUpdateEvent extends AbstractEntityLifecycleEvent
{
}
