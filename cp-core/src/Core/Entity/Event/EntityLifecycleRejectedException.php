<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Thrown by EntityLifecycleListener when a pre_save/pre_delete listener
 * called reject() — aborts the Doctrine flush. Callers may catch this to
 * turn it into a form error via getReasonKey() (a translation key) instead
 * of a 500.
 */
final class EntityLifecycleRejectedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reasonKey,
        string $entityTypeId,
    ) {
        parent::__construct(sprintf('Entity lifecycle rejected for "%s": %s', $entityTypeId, $reasonKey));
    }

    public function getReasonKey(): string
    {
        return $this->reasonKey;
    }
}
