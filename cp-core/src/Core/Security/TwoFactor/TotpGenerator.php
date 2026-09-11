<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

/**
 * RFC 6238 time-based one-time passwords, plus the RFC 4648 base32 codec the
 * authenticator-app provisioning URI requires.
 *
 * Implemented in core rather than pulled in as a dependency: it is ~60 lines of
 * well-specified arithmetic, and an authentication primitive is the last thing
 * that should acquire an external release cadence.
 */
final class TotpGenerator
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const SECRET_BYTES = 20;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * @param int $drift How many periods either side of now are accepted, to
     *   tolerate clock skew between the server and the user's phone.
     */
    public function verify(string $base32Secret, string $code, int $drift = 1, ?int $timestamp = null): bool
    {
        return $this->matchedCounter($base32Secret, $code, $drift, $timestamp) !== null;
    }

    /**
     * @return int|null The time counter the code belongs to, or null when it does
     *   not match. Callers persist the counter to refuse a replay of the same code
     *   inside its 30-second window.
     */
    public function matchedCounter(string $base32Secret, string $code, int $drift = 1, ?int $timestamp = null): ?int
    {
        // Only the separators an authenticator app actually renders are stripped.
        // Stripping every non-digit meant "abc287082xyz" normalised to a valid code:
        // harmless on its own, but it turns the one place that defines what a code
        // *is* into a substring search, which is the wrong shape for a credential.
        $code = str_replace([' ', '-', "\t", "\u{00A0}"], '', trim($code));
        if (\strlen($code) !== self::DIGITS || preg_match('/^\d+$/', $code) !== 1) {
            return null;
        }

        $secret = $this->base32Decode($base32Secret);
        if ($secret === null) {
            return null;
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $drift = max(0, min(10, $drift));

        for ($offset = -$drift; $offset <= $drift; ++$offset) {
            $candidate = $counter + $offset;

            // hash_equals, not ===: the comparison must not leak how many leading
            // digits an attacker guessed correctly.
            if (hash_equals($this->at($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public function currentCode(string $base32Secret, ?int $timestamp = null): string
    {
        $secret = $this->base32Decode($base32Secret);
        if ($secret === null) {
            return '';
        }

        return $this->at($secret, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /**
     * otpauth:// URI consumed by Google Authenticator, Aegis, 1Password and friends.
     */
    public function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    private function at(string $secret, int $counter): string
    {
        if ($counter < 0) {
            return '';
        }

        $hash = hash_hmac('sha1', pack('J', $counter), $secret, true);
        $offset = \ord($hash[19]) & 0x0F;

        $binary = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[(int) bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $out;
    }

    private function base32Decode(string $encoded): ?string
    {
        $encoded = strtoupper(str_replace([' ', '-', '='], '', trim($encoded)));
        if ($encoded === '') {
            return null;
        }

        $bits = '';
        $length = \strlen($encoded);
        for ($i = 0; $i < $length; ++$i) {
            $index = strpos(self::BASE32_ALPHABET, $encoded[$i]);
            if ($index === false) {
                return null;
            }
            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial group is base32 padding, not data.
            if (\strlen($chunk) === 8) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes === '' ? null : $bytes;
    }
}
