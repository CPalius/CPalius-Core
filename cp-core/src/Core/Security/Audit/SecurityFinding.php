<?php

declare(strict_types=1);

namespace App\Core\Security\Audit;

/**
 * One result from the security audit. Message keys are translated by the caller so
 * the same finding renders in the console and in AACP.
 */
final class SecurityFinding
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_PASS = 'pass';

    /** Weight each severity removes from the posture score. */
    private const PENALTY = [
        self::SEVERITY_CRITICAL => 25,
        self::SEVERITY_HIGH => 12,
        self::SEVERITY_MEDIUM => 6,
        self::SEVERITY_LOW => 2,
        self::SEVERITY_PASS => 0,
    ];

    /**
     * @param string $id Stable identifier, so a finding can be referenced in docs.
     * @param string $titleKey Translation key for the check name.
     * @param string $detailKey Translation key explaining the current state.
     * @param array<string, mixed> $parameters Translation parameters for $detailKey.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $severity,
        public readonly string $titleKey,
        public readonly string $detailKey,
        public readonly array $parameters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function pass(string $id, string $titleKey, string $detailKey, array $parameters = []): self
    {
        return new self($id, self::SEVERITY_PASS, $titleKey, $detailKey, $parameters);
    }

    public function isPass(): bool
    {
        return $this->severity === self::SEVERITY_PASS;
    }

    public function penalty(): int
    {
        return self::PENALTY[$this->severity] ?? 0;
    }
}
