<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * One result from a health check.
 *
 * Unlike SecurityFinding, the messages here are plain strings rather than
 * translation keys. cp:doctor is an operator tool that runs in a terminal,
 * often on a broken installation where the translation catalogue itself may be
 * one of the things that is wrong — a diagnostic that cannot render its own
 * output when the system degrades is not a diagnostic.
 */
final class DoctorFinding
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_PASS = 'pass';

    /** Worst to mildest. Used for sorting and for --fail-on comparison. */
    public const ORDER = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
        self::SEVERITY_PASS,
    ];

    /**
     * @param string      $id     stable identifier, so a finding can be referenced in docs and tickets
     * @param string      $title  short name of the check
     * @param string      $detail what was actually observed
     * @param string|null $remedy the command or edit that fixes it, when there is a single obvious one
     */
    public function __construct(
        public readonly string $id,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
        public readonly ?string $remedy = null,
    ) {
    }

    public static function pass(string $id, string $title, string $detail): self
    {
        return new self($id, self::SEVERITY_PASS, $title, $detail);
    }

    public function isPass(): bool
    {
        return $this->severity === self::SEVERITY_PASS;
    }

    /**
     * Rank within self::ORDER; unknown severities sort last so a typo in a
     * check cannot promote its finding above a real critical one.
     */
    public function rank(): int
    {
        $rank = array_search($this->severity, self::ORDER, true);

        return $rank === false ? \count(self::ORDER) : $rank;
    }

    /**
     * True when this finding is at least as severe as $threshold.
     * An unknown threshold matches nothing, so a mistyped --fail-on cannot
     * silently turn the gate off... it is rejected by the command instead.
     */
    public function isAtLeast(string $threshold): bool
    {
        $limit = array_search($threshold, self::ORDER, true);

        if ($limit === false) {
            return false;
        }

        return !$this->isPass() && $this->rank() <= $limit;
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, remedy: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ];
    }
}
