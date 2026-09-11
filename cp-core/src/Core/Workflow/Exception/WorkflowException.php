<?php

declare(strict_types=1);

namespace App\Core\Workflow\Exception;

/**
 * Raised when a transition cannot be applied: unknown workflow/transition,
 * wrong source place, or a denied capability.
 */
final class WorkflowException extends \RuntimeException
{
    public static function unknownWorkflow(string $name): self
    {
        return new self(sprintf('Unknown workflow "%s".', $name));
    }

    public static function unknownTransition(string $workflow, string $transition): self
    {
        return new self(sprintf('Workflow "%s" has no transition "%s".', $workflow, $transition));
    }

    public static function notApplicable(string $workflow, string $transition, string $place): self
    {
        return new self(sprintf('Transition "%s" of workflow "%s" cannot run from place "%s".', $transition, $workflow, $place));
    }

    public static function denied(string $workflow, string $transition): self
    {
        return new self(sprintf('Not allowed to run transition "%s" of workflow "%s".', $transition, $workflow));
    }
}
