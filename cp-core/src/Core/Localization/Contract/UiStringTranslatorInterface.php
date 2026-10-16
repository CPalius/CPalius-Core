<?php

declare(strict_types=1);

namespace App\Core\Localization\Contract;

/**
 * Translates interface strings (admin and site UI labels) between locales.
 *
 * Core defines the contract and uses it when something implements it; the AI
 * module is the implementation. Core never imports the module (Law 2): with no
 * implementation the "translate with AI" actions simply do not appear.
 */
interface UiStringTranslatorInterface
{
    public function isAvailable(): bool;

    /**
     * How many strings one call can reasonably handle (the caller batches).
     */
    public function batchSize(): int;

    /**
     * @param array<string, string> $strings key => source text
     *
     * @return array<string, string> key => translation; a key the provider could not translate is left out
     *
     * @throws \RuntimeException when the provider is unreachable or refuses the request
     */
    public function translateBatch(string $sourceLocale, string $targetLocale, array $strings): array;
}
