<?php

declare(strict_types=1);

namespace App\Core\Trash;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets a module bring its own soft-deleted entity into the recycle bin when "clear deletedAt" is
 * not enough to bring it back (counters, discussion state, caches). Entities that use
 * SoftDeletableTrait need no handler.
 *
 * Module note: an _instanceof rule in core does not reach a module's services, so the module's own
 * services.yaml must tag implementations with cp.trash_soft_handler.
 */
#[AutoconfigureTag('cp.trash_soft_handler')]
interface SoftDeleteHandlerInterface
{
    /** @param class-string $class */
    public function supports(string $class): bool;

    public function restore(object $entity): void;
}
