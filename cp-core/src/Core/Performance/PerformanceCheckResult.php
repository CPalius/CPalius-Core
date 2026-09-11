<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Value object for a performance backend probe result; $messageKey is a translation key, not display text.
 * Separates not_installed vs connection_failed so AACP can show the correct message without storing translated strings in DB.
 */
final class PerformanceCheckResult
{
    /**
     * @param 'ok'|'not_installed'|'connection_failed'|'misconfigured' $status
     * @param array<string, string|int|float> $messageParams ICU MessageFormat params injected at render time via |trans(params).
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $messageKey,
        public readonly array $messageParams = [],
        public readonly ?float $latencyMs = null,
        public readonly array $details = [],
    ) {
    }

    /**
     * @param array<string, string|int|float> $messageParams
     * @param array<string, mixed> $details
     */
    public static function ok(string $messageKey, array $messageParams, float $latencyMs, array $details = []): self
    {
        return new self(true, 'ok', $messageKey, $messageParams, $latencyMs, $details);
    }

    /**
     * @param array<string, string|int|float> $messageParams
     */
    public static function notInstalled(string $messageKey, array $messageParams = []): self
    {
        return new self(false, 'not_installed', $messageKey, $messageParams);
    }

    /**
     * @param array<string, string|int|float> $messageParams
     */
    public static function connectionFailed(string $messageKey, array $messageParams = [], ?float $latencyMs = null): self
    {
        return new self(false, 'connection_failed', $messageKey, $messageParams, $latencyMs);
    }

    public static function misconfigured(string $messageKey): self
    {
        return new self(false, 'misconfigured', $messageKey);
    }

    /**
     * @return array{success: bool, status: string, messageKey: string, messageParams: array<string, mixed>, latencyMs: ?float, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'messageKey' => $this->messageKey,
            'messageParams' => $this->messageParams,
            'latencyMs' => $this->latencyMs,
            'details' => $this->details,
        ];
    }
}
