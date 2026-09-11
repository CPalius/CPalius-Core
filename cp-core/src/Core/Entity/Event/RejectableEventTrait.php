<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * A "pre" event's veto: a listener that ran successfully and made a deliberate
 * business decision to stop the operation calls reject() — this is control
 * flow, not an exception. EntityLifecycleListener turns a rejection into
 * EntityLifecycleRejectedException at the Doctrine boundary, aborting the
 * flush. A listener that instead THROWS a bug is isolated and quarantined
 * like any other hook failure — the operation proceeds; only a deliberate
 * reject() stops it.
 */
trait RejectableEventTrait
{
    private bool $rejected = false;

    private ?string $rejectionReason = null;

    public function reject(string $reasonKey): void
    {
        $this->rejected = true;
        $this->rejectionReason = $reasonKey;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }
}
