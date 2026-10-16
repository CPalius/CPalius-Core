<?php

declare(strict_types=1);

namespace App\Core\Version\Service;

/**
 * Verifies a release manifest against the Ed25519 public key built into core.
 * Checks signature, expiry (freeze attacks) and version floor (rollback attacks).
 */
final class ReleaseVerifier
{
    /** Public half of the publisher key; rotating it needs a signed core release. */
    public const PUBLIC_KEY = 'CXjkEWFM7AAR0rWcvnTw6ViDQra1Pe+kl9qPnCPHhjY=';

    /**
     * @param array<string, mixed> $manifest decoded latest.json
     * @param string               $running  version currently installed
     * @param string|null          $highest  highest version ever accepted here
     *
     * @throws ReleaseSecurityException when the manifest must not be trusted
     */
    public function verify(array $manifest, string $running, ?string $highest = null, ?\DateTimeImmutable $now = null): void
    {
        $this->verifySignature($manifest);

        $now ??= new \DateTimeImmutable();
        $expires = \is_string($manifest['expires_at'] ?? null) ? date_create_immutable($manifest['expires_at']) : false;

        if ($expires === false) {
            throw new ReleaseSecurityException('Manifest has no valid expires_at.');
        }

        if ($expires <= $now) {
            throw new ReleaseSecurityException('Manifest expired on '.$expires->format(\DATE_ATOM).' (possible freeze attack).');
        }

        $version = (string) ($manifest['version'] ?? '');
        $min = (string) ($manifest['min_allowed_version'] ?? '');

        if ($min === '' || version_compare($version, $min, '<')) {
            throw new ReleaseSecurityException('Manifest version is below its own min_allowed_version.');
        }

        // Equal is fine: it just means "up to date". Only going backwards is an attack.
        $floor = $highest !== null && version_compare($highest, $running, '>') ? $highest : $running;

        if (version_compare($version, $floor, '<')) {
            throw new ReleaseSecurityException(\sprintf('Manifest version %s is older than %s (possible rollback attack).', $version, $floor));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function verifySignature(array $manifest): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            throw new ReleaseSecurityException('PHP sodium extension is required to verify releases.');
        }

        $encoded = $manifest['signature'] ?? null;
        $signature = \is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($signature === false || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ReleaseSecurityException('Manifest is unsigned or the signature is malformed.');
        }

        $key = base64_decode(self::PUBLIC_KEY, true);

        if ($key === false || !sodium_crypto_sign_verify_detached($signature, ReleaseSigner::canonical($manifest), $key)) {
            throw new ReleaseSecurityException('Signature does not match the built-in public key.');
        }
    }
}
