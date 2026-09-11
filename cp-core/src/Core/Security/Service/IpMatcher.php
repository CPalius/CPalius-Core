<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

/**
 * IPv4/IPv6 literal and CIDR matching for the ban list and the allowlist.
 * Symfony's IpUtils covers this, but bans are checked on every request and a
 * malformed operator-typed pattern must never raise — hence an explicit matcher
 * that returns false on anything it cannot parse.
 */
final class IpMatcher
{
    public function matches(string $ip, string $pattern): bool
    {
        $ip = trim($ip);
        $pattern = trim($pattern);

        if ($ip === '' || $pattern === '') {
            return false;
        }

        if (!str_contains($pattern, '/')) {
            // Textual equality is not address equality: "::1" and
            // "0:0:0:0:0:0:0:1" are the same host, and a dual-stack server
            // reports an IPv4 client as "::ffff:1.2.3.4". Comparing the packed
            // bytes means a ban cannot be evaded — and an allowlisted operator
            // cannot be locked out — by writing the address a different way.
            $packedIp = $this->pack($ip);
            $packedPattern = $this->pack($pattern);

            if (\is_string($packedIp) && \is_string($packedPattern)) {
                return \strlen($packedIp) === \strlen($packedPattern) && hash_equals($packedPattern, $packedIp);
            }

            return strcasecmp($ip, $pattern) === 0;
        }

        [$subnet, $bits] = explode('/', $pattern, 2);
        if (!preg_match('/^\d{1,3}$/', $bits)) {
            return false;
        }

        $packedIp = $this->pack($ip);
        $packedSubnet = $this->pack(trim($subnet));

        if (!\is_string($packedIp) || !\is_string($packedSubnet)) {
            return false;
        }

        // Mixing families (a v4 address against a v6 range) is not a match, not an error.
        if (\strlen($packedIp) !== \strlen($packedSubnet)) {
            return false;
        }

        $prefix = (int) $bits;
        $maxBits = \strlen($packedIp) * 8;
        if ($prefix > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedSubnet, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $prefix % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (\ord($packedIp[$wholeBytes]) & $mask) === (\ord($packedSubnet[$wholeBytes]) & $mask);
    }

    /**
     * Canonical text form of an address: the compressed IPv6 form, or the plain
     * IPv4 form for an IPv4-mapped address. Used when a pattern is persisted so
     * the stored row and the incoming request agree on one spelling; anything
     * that is not an address is returned trimmed and untouched.
     */
    public function canonicalize(string $ip): string
    {
        $ip = trim($ip);
        $packed = $this->pack($ip);

        if (!\is_string($packed)) {
            return $ip;
        }

        $text = @inet_ntop($packed);

        return \is_string($text) ? $text : $ip;
    }

    /**
     * Canonicalizes the address half of a literal or CIDR pattern, leaving the
     * prefix length alone.
     */
    public function canonicalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (!str_contains($pattern, '/')) {
            return $this->canonicalize($pattern);
        }

        [$subnet, $bits] = explode('/', $pattern, 2);

        return $this->canonicalize($subnet).'/'.$bits;
    }

    /**
     * inet_pton plus one normalisation: an IPv4-mapped IPv6 address
     * (::ffff:1.2.3.4) is unwrapped to its four IPv4 bytes. Without this a ban on
     * 1.2.3.0/24 silently misses a client that the stack reports in mapped form,
     * which is the default on a dual-stack listener.
     */
    private function pack(string $ip): string|false
    {
        $packed = @inet_pton($ip);

        if (!\is_string($packed)) {
            return false;
        }

        // The mapped prefix is ten zero bytes followed by 0xFF 0xFF; spelled out
        // with chr() so the constant cannot be mangled by a re-encoding of this file.
        if (\strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat(\chr(0), 10).\chr(255).\chr(255)) {
            return substr($packed, 12);
        }

        return $packed;
    }

    /**
     * @param list<string> $patterns
     */
    public function matchesAny(string $ip, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isValidPattern(string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || \strlen($pattern) > 45) {
            return false;
        }

        if (!str_contains($pattern, '/')) {
            return filter_var($pattern, \FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $pattern, 2);
        if (!preg_match('/^\d{1,3}$/', $bits)) {
            return false;
        }

        // pack(), not inet_pton(): "::ffff:10.0.0.0/8" is an IPv4 range written
        // in mapped form, so its prefix must be validated against 32 bits.
        $packed = $this->pack(trim($subnet));
        if (!\is_string($packed)) {
            return false;
        }

        return (int) $bits <= \strlen($packed) * 8;
    }

    /**
     * Splits an operator-typed list (newlines, commas or spaces) into patterns.
     *
     * @return list<string>
     */
    public function parseList(string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_filter(
            array_map('trim', $tokens),
            fn (string $token): bool => $token !== '' && $this->isValidPattern($token),
        ));
    }
}
