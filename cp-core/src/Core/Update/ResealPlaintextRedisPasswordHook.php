<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Settings\SettingSecretCodec;
use Doctrine\DBAL\Connection;

/**
 * `performance.redis.password` was declared `#[CpSetting(type: 'text')]` instead
 * of `type: 'password'`, so `SystemSettingsService` never sealed it: any value an
 * operator saved there sat in `cp_settings` in plain text and was echoed back
 * unmasked into the AACP performance settings form. The definition is fixed in
 * 2.2.10; this hook seals whatever plaintext value is already on disk so an
 * existing install doesn't keep an unencrypted Redis credential around until
 * someone happens to re-save the field.
 */
final class ResealPlaintextRedisPasswordHook implements UpdateHookInterface
{
    private const KEY = 'performance.redis.password';

    public function __construct(
        private readonly Connection $connection,
        private readonly SettingSecretCodec $codec,
    ) {
    }

    public function id(): string
    {
        return 'core.2_2_10.reseal_plaintext_redis_password';
    }

    public function version(): string
    {
        return '2.2.10';
    }

    public function description(): string
    {
        return 'Encrypts an existing plaintext performance.redis.password value left over from the type: text definition bug.';
    }

    public function run(): ?string
    {
        try {
            $raw = $this->connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => self::KEY],
            );
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($raw) || $raw === '' || $this->codec->isSealed($raw)) {
            return null;
        }

        $sealed = $this->codec->seal($raw);
        if ($sealed === $raw) {
            // seal() itself failed and returned the input unchanged; nothing to record.
            return null;
        }

        try {
            $this->connection->executeStatement(
                'UPDATE cp_settings SET setting_value = :value WHERE setting_key = :key',
                ['value' => $sealed, 'key' => self::KEY],
            );
        } catch (\Throwable) {
            return null;
        }

        return 'sealed '.self::KEY;
    }
}
