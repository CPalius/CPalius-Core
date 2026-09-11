<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

/**
 * Per-invocation bag passed to every filter in a format's chain.
 */
final class TextFilterContext
{
    public const PHASE_STORAGE = 'storage';
    public const PHASE_OUTPUT = 'output';

    /**
     * @param array<string, mixed> $settings filter-specific settings from the format
     * @param array<string, mixed> $tokenContext TokenReplacer subjects
     */
    public function __construct(
        public readonly string $formatId,
        public readonly string $phase,
        public readonly array $settings = [],
        public readonly array $tokenContext = [],
    ) {
    }

    public function isStorage(): bool
    {
        return $this->phase === self::PHASE_STORAGE;
    }

    public function isOutput(): bool
    {
        return $this->phase === self::PHASE_OUTPUT;
    }
}
