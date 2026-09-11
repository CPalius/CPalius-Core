<?php

declare(strict_types=1);

namespace App\Core\Update;

/**
 * What one stage of cp:update did.
 *
 * A step reports rather than throws, because an update run that stops at the
 * first problem leaves the installation in a state nobody described. The runner
 * decides whether a failure is fatal to the rest of the pipeline; the step only
 * says what happened.
 */
final class UpdateStepResult
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<string> $details one line per unit of work actually performed
     */
    private function __construct(
        public readonly string $step,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {
    }

    /**
     * @param list<string> $details
     */
    public static function applied(string $step, string $summary, array $details = []): self
    {
        return new self($step, self::STATUS_APPLIED, $summary, $details);
    }

    /**
     * Nothing to do. Distinct from "applied" with an empty list so an operator
     * can tell "already up to date" from "did something invisible".
     */
    public static function skipped(string $step, string $summary): self
    {
        return new self($step, self::STATUS_SKIPPED, $summary);
    }

    /**
     * @param list<string> $details
     */
    public static function failed(string $step, string $summary, array $details = []): self
    {
        return new self($step, self::STATUS_FAILED, $summary, $details);
    }

    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function changedAnything(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /**
     * @return array{step: string, status: string, summary: string, details: list<string>}
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'status' => $this->status,
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }
}
