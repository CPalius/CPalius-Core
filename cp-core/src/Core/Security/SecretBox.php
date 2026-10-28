<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Encrypts secrets at rest.
 *
 * Formats, newest first:
 *  - "v3:<kid>:<base64>" AES-256-GCM with a key derived by HKDF from one secret. The kid names which
 *    secret sealed it, so after APP_SECRET is replaced the old value is still read with the secret listed in
 *    APP_SECRET_PREVIOUS, and cp:secrets:rotate re-seals everything under the new one.
 *  - "v2:<base64>" AES-256-GCM, key = sha256(secret). Read-only.
 *  - unprefixed AES-256-CBC. Read-only.
 */
final class SecretBox
{
    private const VERSION_GCM_KID = 'v3:';
    private const VERSION_GCM = 'v2:';
    private const HKDF_INFO = 'cpalius/secretbox/v3';

    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const LEGACY_IV_BYTES = 16;

    /** @var list<string> the current secret first, then the ones it replaced */
    private readonly array $secrets;

    /** @var array<string, string> kid => derived key */
    private readonly array $keysByKid;

    /**
     * @param string $previousSecrets comma-separated secrets this install used before (APP_SECRET_PREVIOUS)
     */
    public function __construct(
        private readonly string $appSecret,
        ?string $previousSecrets = null,
    ) {
        $secrets = [$appSecret];
        foreach (explode(',', (string) $previousSecrets) as $old) {
            $old = trim($old);
            if ($old !== '' && !\in_array($old, $secrets, true)) {
                $secrets[] = $old;
            }
        }
        $this->secrets = $secrets;

        $byKid = [];
        foreach ($secrets as $secret) {
            $byKid[self::kid($secret)] = self::derive($secret);
        }
        $this->keysByKid = $byKid;
    }

    public function seal(string $plain): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $kid = self::kid($this->appSecret);

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $this->keysByKid[$kid],
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::VERSION_GCM_KID.$kid,
            self::TAG_BYTES,
        );

        if (!\is_string($cipher) || \strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Unable to seal secret.');
        }

        return self::VERSION_GCM_KID.$kid.':'.base64_encode($iv.$tag.$cipher);
    }

    public function open(string $sealed): string
    {
        if (str_starts_with($sealed, self::VERSION_GCM_KID)) {
            return $this->openKeyed($sealed);
        }

        if (!str_starts_with($sealed, self::VERSION_GCM)) {
            return $this->openLegacyCbc($sealed);
        }

        $raw = self::decode(substr($sealed, \strlen(self::VERSION_GCM)));

        foreach ($this->secrets as $secret) {
            $plain = $this->decrypt($raw, hash('sha256', $secret, true), '');
            if ($plain !== null) {
                return $plain;
            }
        }

        throw new \RuntimeException('Unable to open secret.');
    }

    /**
     * True when the value is not sealed in the newest format under the current secret, i.e. cp:secrets:rotate
     * has work to do on it.
     */
    public function needsRewrap(string $sealed): bool
    {
        return !str_starts_with($sealed, self::VERSION_GCM_KID.self::kid($this->appSecret).':');
    }

    /**
     * The secrets that may have produced an HMAC stored before a rotation, current one first.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    private function openKeyed(string $sealed): string
    {
        $parts = explode(':', $sealed, 3);
        $key = $this->keysByKid[$parts[1] ?? ''] ?? null;
        if ($key === null || !isset($parts[2])) {
            throw new \RuntimeException('Unable to open secret.');
        }

        $plain = $this->decrypt(self::decode($parts[2]), $key, self::VERSION_GCM_KID.$parts[1]);
        if ($plain === null) {
            throw new \RuntimeException('Unable to open secret.');
        }

        return $plain;
    }

    private static function decode(string $base64): string
    {
        $raw = base64_decode($base64, true);

        // An empty ciphertext body is legitimate (seal('') round-trips), so the
        // minimum is the nonce plus the tag, not one byte more.
        if (!\is_string($raw) || \strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Unable to open secret.');
        }

        return $raw;
    }

    private function decrypt(string $raw, string $key, string $aad): ?string
    {
        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            $key,
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
            $aad,
        );

        return \is_string($plain) ? $plain : null;
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

        foreach ($this->secrets as $secret) {
            $plain = openssl_decrypt(
                substr($raw, self::LEGACY_IV_BYTES),
                'aes-256-cbc',
                hash('sha256', $secret, true),
                \OPENSSL_RAW_DATA,
                substr($raw, 0, self::LEGACY_IV_BYTES),
            );

            if (\is_string($plain)) {
                return $plain;
            }
        }

        throw new \RuntimeException('Unable to open secret.');
    }

    /** Public, non-reversible label of a secret: which key sealed a value, never the key itself. */
    private static function kid(string $secret): string
    {
        return substr(hash_hmac('sha256', 'kid', $secret), 0, 8);
    }

    private static function derive(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO);
    }
}
