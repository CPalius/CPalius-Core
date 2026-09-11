<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Encrypts secrets at rest with a key derived from kernel.secret. Never log plaintext.
 *
 * New ciphertext is AES-256-GCM. The previous format was AES-256-CBC with no
 * authentication tag, which meant anyone who could write to the row could flip
 * ciphertext bits and have the plaintext change underneath us — a 2FA secret or
 * an SMTP password is exactly the kind of value where "decrypts to something"
 * must also mean "is what we wrote". GCM detects the tampering and open() throws
 * instead of returning an attacker-shaped secret.
 *
 * The versioned "v2:" prefix is what makes the upgrade non-breaking: values
 * written before this change have no prefix and are still read as CBC, so no
 * migration and no re-entry of every secret is required. Rewriting a value
 * (any seal()) silently upgrades it.
 */
final class SecretBox
{
    /** Marks the authenticated format; absence of a marker means legacy CBC. */
    private const VERSION_GCM = 'v2:';

    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const LEGACY_IV_BYTES = 16;

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    public function seal(string $plain): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $this->key(),
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if (!\is_string($cipher) || \strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Unable to seal secret.');
        }

        return self::VERSION_GCM.base64_encode($iv.$tag.$cipher);
    }

    public function open(string $sealed): string
    {
        if (!str_starts_with($sealed, self::VERSION_GCM)) {
            return $this->openLegacyCbc($sealed);
        }

        $raw = base64_decode(substr($sealed, \strlen(self::VERSION_GCM)), true);

        // An empty ciphertext body is legitimate (seal('') round-trips), so the
        // minimum is the nonce plus the tag, not one byte more.
        if (!\is_string($raw) || \strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Unable to open secret.');
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            $this->key(),
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
        );

        if (!\is_string($plain)) {
            throw new \RuntimeException('Unable to open secret.');
        }

        return $plain;
    }

    /**
     * Reads a value sealed before the GCM format existed. Kept read-only: nothing
     * writes CBC any more, so this shrinks to nothing as secrets are rewritten.
     */
    private function openLegacyCbc(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if (!\is_string($raw) || \strlen($raw) <= self::LEGACY_IV_BYTES) {
            throw new \RuntimeException('Unable to open secret.');
        }

        $plain = openssl_decrypt(
            substr($raw, self::LEGACY_IV_BYTES),
            'aes-256-cbc',
            $this->key(),
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::LEGACY_IV_BYTES),
        );

        if (!\is_string($plain)) {
            throw new \RuntimeException('Unable to open secret.');
        }

        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', $this->appSecret, true);
    }
}
