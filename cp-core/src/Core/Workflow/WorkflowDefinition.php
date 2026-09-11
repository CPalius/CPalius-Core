<?php

declare(strict_types=1);

namespace App\Core\Workflow;

/**
 * An immutable state machine: a set of places and the transitions between them.
 * The current place is read from / written to a property on the subject
 * (default "status", via getStatus()/setStatus()).
 */
final class WorkflowDefinition
{
    /**
     * @param list<string>                      $places
     * @param array<string, WorkflowTransition> $transitions   transition name => transition
     * @param array<string, string>             $placeLabels
     * @param list<string>                      $publishPlaces places that mean "content is live"
     */
    public function __construct(
        public readonly string $name,
        public readonly array $places,
        public readonly array $transitions,
        public readonly string $initialPlace,
        public readonly string $subjectProperty = 'status',
        public readonly bool $auditable = true,
        public readonly array $placeLabels = [],
        public readonly array $publishPlaces = [],
    ) {
    }

    public function isPublishPlace(string $place): bool
    {
        return \in_array($place, $this->publishPlaces, true);
    }

    public function hasPlace(string $place): bool
    {
        return \in_array($place, $this->places, true);
    }

    public function getTransition(string $name): ?WorkflowTransition
    {
        return $this->transitions[$name] ?? null;
    }

    /**
     * @return list<WorkflowTransition>
     */
    public function transitionsFrom(string $place): array
    {
        return array_values(array_filter(
            $this->transitions,
            static fn (WorkflowTransition $t): bool => $t->acceptsFrom($place),
        ));
    }

    public function placeLabel(string $place): string
    {
        return $this->placeLabels[$place] ?? $place;
    }
}
