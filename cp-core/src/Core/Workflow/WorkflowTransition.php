<?php

declare(strict_types=1);

namespace App\Core\Workflow;

/**
 * One edge in a workflow: from one or more places to exactly one place, optionally
 * gated by a capability.
 */
final class WorkflowTransition
{
    /**
     * @param list<string> $from
     */
    public function __construct(
        public readonly string $name,
        public readonly array $from,
        public readonly string $to,
        public readonly ?string $capability = null,
        public readonly ?string $label = null,
    ) {
    }

    public function acceptsFrom(string $place): bool
    {
        return \in_array($place, $this->from, true);
    }
}
