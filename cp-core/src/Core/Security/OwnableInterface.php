<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Owner id for parametric ".own" capabilities. Implemented by content entities such as Node.
 */
interface OwnableInterface
{
    /**
     * Owning user id, or null when the entity has no owner.
     */
    public function getOwnerId(): ?int;
}
