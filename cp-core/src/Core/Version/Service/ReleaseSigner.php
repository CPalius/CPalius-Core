<?php

declare(strict_types=1);

namespace App\Core\Version\Service;

/**
 * Signs latest.json with an Ed25519 secret key on the publisher's machine.
 * The key never ships in a package; only ReleaseVerifier's public key does.
 */
final class ReleaseSigner
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    public const DEFAULT_MIN_ALLOWED_VERSION = '2.0.0';

    /**
     * The exact bytes that are signed. Both sides call this one method, so a
     * field added to the signature can never be forgotten by one of them.
     *
     * @param array<string, mixed> $manifest
     */
    public static function canonical(array $manifest): string
    {
        $download = \is_array($manifest['download'] ?? null) ? $manifest['download'] : [];

        return json_encode([
            'version' => (string) ($manifest['version'] ?? ''),
            'released_at' => (string) ($manifest['released_at'] ?? ''),
            'expires_at' => (string) ($manifest['expires_at'] ?? ''),
            'min_allowed_version' => (string) ($manifest['min_allowed_version'] ?? ''),
            'critical' => (bool) ($manifest['critical'] ?? false),
            'zip' => (string) ($download['zip'] ?? ''),
            'sha256' => strtolower((string) ($download['sha256'] ?? '')),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the manifest with expires_at, min_allowed_version and signature set.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    public function sign(array $manifest, string $secretKeyBase64, int $validityDays = self::DEFAULT_VALIDITY_DAYS): array
    {
        $secret = base64_decode(trim($secretKeyBase64), true);

        if ($secret === false || \strlen($secret) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Signing key is not a valid Ed25519 secret key.');
        }

        $manifest['expires_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+'.$validityDays.' days')
            ->format(\DATE_ATOM);

        if (!\is_string($manifest['min_allowed_version'] ?? null) || $manifest['min_allowed_version'] === '') {
            $manifest['min_allowed_version'] = self::DEFAULT_MIN_ALLOWED_VERSION;
        }

        $manifest['signature_alg'] = 'ed25519';
        $manifest['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($manifest), $secret));

        sodium_memzero($secret);

        return $manifest;
    }

    /**
     * Re-signs a latest.json file in place; used by yayinla.sh before upload.
     */
    public function signFile(string $manifestPath, string $keyPath, int $validityDays = self::DEFAULT_VALIDITY_DAYS): void
    {
        $key = is_file($keyPath) ? file_get_contents($keyPath) : false;
        $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if ($key === false || $raw === false) {
            throw new \RuntimeException('Signing key or latest.json is missing.');
        }

        $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($manifest)) {
            throw new \RuntimeException('latest.json is not a JSON object.');
        }

        $signed = $this->sign($manifest, $key, $validityDays);

        file_put_contents(
            $manifestPath,
            json_encode($signed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );
    }
}
