<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Security\SecretBox;

/**
 * Encrypts #[CpSetting(type: 'password')] values at rest.
 *
 * Captcha secret keys, SMTP passwords and API credentials all live in cp_settings,
 * where a read-only SQL injection or a shared database backup would otherwise hand
 * them over in plain text.
 *
 * The stored form carries an explicit marker so a value written before this
 * existed is recognised as legacy plaintext instead of being mistaken for
 * corrupted ciphertext.
 */
final class SettingSecretCodec
{
    private const MARKER = 'cpenc:v1:';

    public function __construct(
        private readonly SecretBox $secretBox,
    ) {
    }

    public function seal(string $plain): string
    {
        if ($plain === '' || $this->isSealed($plain)) {
            return $plain;
        }

        try {
            return self::MARKER.$this->secretBox->seal($plain);
        } catch (\Throwable) {
            // Refusing to store the secret at all would silently discard an
            // operator's input; storing it as-is keeps the previous behaviour.
            return $plain;
        }
    }

    public function reveal(string $stored): string
    {
        if (!$this->isSealed($stored)) {
            return $stored;
        }

        try {
            return $this->secretBox->open(substr($stored, \strlen(self::MARKER)));
        } catch (\Throwable) {
            // Wrong APP_SECRET, or a truncated value: an empty secret fails closed
            // (the feature using it reports "not configured") instead of leaking
            // ciphertext into an outbound request.
            return '';
        }
    }

    public function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::MARKER);
    }
}
