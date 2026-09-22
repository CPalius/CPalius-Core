<?php

declare(strict_types=1);

namespace Modules\Ai\Install;

use Doctrine\DBAL\Connection;

/**
 * Inserts translator defaults when a key is missing. Never writes an API key.
 */
final class AiSettingsSeeder
{
    public static function seed(Connection $connection): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;

        foreach (self::rows() as $key => $value) {
            try {
                $existing = $connection->fetchOne(
                    'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                    ['key' => $key],
                );
            } catch (\Throwable) {
                continue;
            }

            if ($existing !== false && $existing !== null) {
                continue;
            }

            try {
                $connection->executeStatement(
                    'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (:key, :value, :module, :now)',
                    ['key' => $key, 'value' => $value, 'module' => 'ai', 'now' => $now],
                );
                ++$written;
            } catch (\Throwable) {
                // Unique race or missing table: skip.
            }
        }

        return $written;
    }

    public static function remapLegacyModel(Connection $connection): bool
    {
        $map = [
            'gemini-flash-latest' => 'gemini-3.5-flash-lite',
            'gemini-2.0-flash-lite' => 'gemini-3.5-flash-lite',
            'gemini-2.0-flash' => 'gemini-3.5-flash-lite',
            'gemini-2.5-flash-lite' => 'gemini-3.5-flash-lite',
            'gemini-2.5-flash' => 'gemini-3.5-flash-lite',
            'gemini-2.5-pro' => 'gemini-3.5-flash-lite',
            'gemini-1.5-flash' => 'gemini-3.5-flash-lite',
            'gemini-1.5-pro' => 'gemini-3.5-flash-lite',
        ];

        try {
            $current = $connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => 'ai.model'],
            );
        } catch (\Throwable) {
            return false;
        }

        if (!\is_string($current) || !isset($map[$current])) {
            return false;
        }

        try {
            $connection->executeStatement(
                'UPDATE cp_settings SET setting_value = :value, updated_at = :now WHERE setting_key = :key',
                [
                    'value' => $map[$current],
                    'key' => 'ai.model',
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function rows(): array
    {
        return [
            'ai.enabled' => '1',
            'ai.provider' => 'gemini',
            'ai.model' => 'gemini-3.5-flash-lite',
            'ai.api_key' => '',
            'ai.timeout' => '25',
            'ai.allow_edit_translate' => '0',
        ];
    }
}
