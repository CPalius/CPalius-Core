<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Version\Service\ReleaseSecurityException;
use App\Core\Version\Service\ReleaseVerifier;

/**
 * Checks the extension index against the same publisher key as release manifests:
 * signature over the exact file bytes, expiry, and a serial that may never go down.
 */
final class ExtensionIndexVerifier
{
    private const MAX_TYPES = ['modules', 'themes'];

    /**
     * @return array<string, mixed> the decoded index
     *
     * @throws ReleaseSecurityException when the index must not be trusted
     */
    public function verify(string $body, string $signatureBase64, ?int $highestSerial = null, ?\DateTimeImmutable $now = null): array
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            throw new ReleaseSecurityException('PHP sodium extension is required to verify the extension index.');
        }

        $signature = base64_decode(trim($signatureBase64), true);
        $key = base64_decode(ReleaseVerifier::PUBLIC_KEY, true);

        if ($signature === false || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES || $key === false) {
            throw new ReleaseSecurityException('The extension index signature is missing or malformed.');
        }

        if (!sodium_crypto_sign_verify_detached($signature, $body, $key)) {
            throw new ReleaseSecurityException('The extension index signature does not match the built-in public key.');
        }

        $index = json_decode($body, true);

        if (!\is_array($index)) {
            throw new ReleaseSecurityException('The extension index is not valid JSON.');
        }

        $expires = \is_string($index['expires_at'] ?? null) ? date_create_immutable($index['expires_at']) : false;

        if ($expires === false || $expires <= ($now ?? new \DateTimeImmutable())) {
            throw new ReleaseSecurityException('The extension index has expired (possible freeze attack).');
        }

        $serial = (int) ($index['serial'] ?? 0);

        if ($highestSerial !== null && $serial < $highestSerial) {
            throw new ReleaseSecurityException(\sprintf('The extension index serial %d is older than %d (possible rollback attack).', $serial, $highestSerial));
        }

        foreach (self::MAX_TYPES as $type) {
            if (!\is_array($index[$type] ?? []) ) {
                throw new ReleaseSecurityException('The extension index has a malformed "'.$type.'" section.');
            }
        }

        return $index;
    }
}
