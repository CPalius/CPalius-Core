<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * A source of exportable / importable configuration. Each provider owns one or
 * more named documents (file stems under cp-content/config/sync/, e.g.
 * "settings" or "field.page"). Export must be deterministic and portable
 * (no ids, no timestamps).
 */
interface ConfigProviderInterface
{
    /**
     * @return list<string> the document names this provider currently exports
     */
    public function documents(): array;

    public function ownsDocument(string $name): bool;

    /**
     * @return array<string, mixed> canonical content of one document
     */
    public function exportDocument(string $name): array;

    /**
     * Human-readable list of what importing $incoming would change vs live state.
     *
     * @param array<string, mixed> $incoming
     *
     * @return list<string>
     */
    public function diffDocument(string $name, array $incoming): array;

    /**
     * Apply one document. Persists via the shared EM but MUST NOT flush — the
     * ConfigManager owns the transaction.
     *
     * @param array<string, mixed> $incoming
     *
     * @return list<string> change descriptions actually applied
     */
    public function importDocument(string $name, array $incoming): array;

    /**
     * Called once after a successful import batch (cache clears, re-index, …).
     */
    public function afterImport(): void;
}
