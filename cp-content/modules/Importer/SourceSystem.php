<?php

declare(strict_types=1);

namespace Modules\Importer;

/**
 * One system content can be brought over from, as the import screen shows it.
 *
 * The catalogue lists systems that are PLANNED as well as ones that work, and
 * marks the difference plainly. Hiding the planned ones would make the screen
 * look finished and leave an operator wondering whether XenForo support exists
 * somewhere they have not found; showing them as buttons that do nothing would
 * be worse. Marked "not yet" is the only version that tells the truth.
 *
 * Availability is not declared, it is derived: a system is available when
 * migrations for it are actually registered. That way this list cannot drift
 * out of step with the code the way a hand-maintained flag would.
 */
final class SourceSystem
{
    /**
     * @param list<string> $brings short labels for what it carries across
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $migrationPrefix,
        public readonly string $icon,
        public readonly array $brings = [],
        public readonly bool $available = false,
    ) {
    }

    public function withAvailability(bool $available): self
    {
        return new self($this->id, $this->label, $this->migrationPrefix, $this->icon, $this->brings, $available);
    }
}
