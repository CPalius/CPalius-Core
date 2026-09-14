<?php

declare(strict_types=1);

namespace App\Core\Storage;

/**
 * Outcome of one live write-probe against a target.
 *
 * Carries a translation key rather than a sentence: the probe runs from a JSON
 * endpoint, from a console command and from the daily cron, and only one of
 * those three has a request locale to translate against.
 */
final readonly class StorageTestResult
{
    /**
     * @param array<string, mixed> $messageParams
     */
    public function __construct(
        public bool $success,
        public string $messageKey,
        public array $messageParams = [],
        public ?float $latencyMs = null,
        public ?string $describe = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function ok(string $describe, float $latencyMs, string $messageKey = 'aacp.storage.test.ok', array $params = []): self
    {
        return new self(true, $messageKey, $params, $latencyMs, $describe);
    }

    /**
     * Driver exceptions arrive here as their message. Where that message is
     * itself a translation key (the drivers throw keys for the failures they
     * can name, such as a missing extension), it is passed through untouched
     * and the translator resolves it; anything else is a server's own words and
     * is shown inside a generic wrapper so it still reads as a sentence.
     */
    public static function fromThrowable(\Throwable $e): self
    {
        $message = $e->getMessage();

        if (str_starts_with($message, 'aacp.storage.error.')) {
            return new self(false, $message);
        }

        return new self(false, 'aacp.storage.test.failed', ['reason' => $message]);
    }
}
