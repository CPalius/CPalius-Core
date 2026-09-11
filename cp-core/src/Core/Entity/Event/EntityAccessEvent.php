<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

use App\Entity\User;

/**
 * Last-resort imperative extension point CPaliusVoter consults for one record
 * when neither a role capability nor a stored EntityAccessGrant (T1.4) settles
 * the question — for dynamic rules that cannot be expressed as a stored grant
 * (time windows, computed business state, …). Complements, does not replace,
 * T1.4: list screens must stay one SQL query (Law 6.2), so this event is
 * single-record only, never consulted for CpEntityQuery::accessCheck().
 *
 * Untouched = abstain (deny). A listener calls allow() or deny(); the first
 * explicit deny() wins over any allow() from another listener (fail-safe).
 */
final class EntityAccessEvent extends AbstractEntityLifecycleEvent
{
    private ?bool $decision = null;

    public function __construct(
        object $entity,
        string $entityTypeId,
        private readonly string $capability,
        private readonly ?User $user,
    ) {
        parent::__construct($entity, $entityTypeId);
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function allow(): void
    {
        if ($this->decision !== false) {
            $this->decision = true;
        }
    }

    public function deny(): void
    {
        $this->decision = false;
    }

    /**
     * true = allow, false = deny, null = no listener decided (abstain).
     */
    public function getDecision(): ?bool
    {
        return $this->decision;
    }
}
