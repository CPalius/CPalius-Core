<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Optional IP allowlist for machine keys. Empty list means any client IP.
 * Uses Request::getClientIp() (trusted proxies only — never raw X-Forwarded-For).
 */
final class ApiClientIpPolicy
{
    /**
     * @param list<string> $allowlist CIDR or exact IPv4/IPv6
     */
    public function allows(string $clientIp, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        $clientIp = trim($clientIp);
        if ($clientIp === '') {
            return false;
        }

        foreach ($allowlist as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($this->matches($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return hash_equals($entry, $ip);
        }

        [$subnet, $bitsRaw] = explode('/', $entry, 2);
        $bits = (int) $bitsRaw;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || \strlen($ipBin) !== \strlen($subnetBin)) {
            return false;
        }

        $maxBits = \strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && !hash_equals(substr($subnetBin, 0, $fullBytes), substr($ipBin, 0, $fullBytes))) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($ipBin[$fullBytes]) & $mask) === (\ord($subnetBin[$fullBytes]) & $mask);
    }
}
