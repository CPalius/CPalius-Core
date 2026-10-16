<?php

declare(strict_types=1);

namespace App\Core\Update;

use Doctrine\DBAL\Connection;

/**
 * 2.2.33 shipped an AI article writer with seven extra providers; 2.2.34 removes it again (it did not
 * earn its keep and most of those free tiers were unusable in practice). Sites that already updated hold
 * the settings that feature wrote, including API keys typed in for the extra providers. Nothing reads
 * them any more, so they are deleted rather than left behind in the database.
 *
 * The older single key (ai.api_key) and the translator settings are NOT touched: translation stays.
 */
final class RemoveAiWriterSettingsHook implements UpdateHookInterface
{
    private const KEYS = [
        'ai.writer_enabled',
        'ai.writer_provider',
        'ai.writer_model',
        'ai.translate_provider',
        'ai.translate_model',
        'ai.openrouter_key',
        'ai.groq_key',
        'ai.gemini_key',
        'ai.cerebras_key',
        'ai.mistral_key',
        'ai.nvidia_key',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function id(): string
    {
        return 'core.2_2_34.remove_ai_writer_settings';
    }

    public function version(): string
    {
        return '2.2.34';
    }

    public function description(): string
    {
        return 'Deletes the settings (and extra provider API keys) left behind by the AI article writer that 2.2.33 shipped and 2.2.34 removed.';
    }

    public function run(): ?string
    {
        try {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM cp_settings WHERE setting_key IN (?)',
                [self::KEYS],
                [Connection::PARAM_STR_ARRAY],
            );
        } catch (\Throwable) {
            // No settings table yet (fresh install mid-way): nothing to remove.
            return null;
        }

        return $deleted > 0 ? sprintf('removed %d leftover AI writer setting(s)', $deleted) : null;
    }
}
