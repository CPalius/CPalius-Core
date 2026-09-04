<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Path matching for Varnish Cache-Control: admin/auth prefixes are always private.
 * Operator excludes are extra prefixes (one per line or comma-separated).
 */
final class VarnishCachePolicy
{
    public const DEFAULT_TTL = 120;

    public const DEFAULT_EXCLUDES = "/aacp\n/admin\n/login\n/logout\n/hesap\n/api\n/_fragment\n/_profiler\n/_wdt";

    /** @var list<string> */
    public const HARD_PRIVATE_PREFIXES = [
        '/aacp',
        '/admin',
        '/login',
        '/logout',
        '/hesap',
        '/api',
        '/_fragment',
        '/_profiler',
        '/_wdt',
    ];

    /**
     * @return list<string>
     */
    public function parseExcludes(string $raw): array
    {
        $parts = \preg_split('/[\s,]+/', \trim($raw)) ?: [];
        $prefixes = [];

        foreach ($parts as $part) {
            $part = \trim($part);
            if ($part === '' || $part === '/') {
                continue;
            }
            if ($part[0] !== '/') {
                $part = '/'.$part;
            }
            $prefixes[] = \rtrim($part, '/') === '' ? '/' : \rtrim($part, '/');
        }

        return \array_values(\array_unique($prefixes));
    }

    public function isHardPrivatePath(string $path): bool
    {
        return $this->matchesAnyPrefix($path, self::HARD_PRIVATE_PREFIXES);
    }

    /**
     * @param list<string> $excludes
     */
    public function isExcludedPath(string $path, array $excludes): bool
    {
        return $this->matchesAnyPrefix($path, $excludes);
    }

    /**
     * @param list<string> $prefixes
     */
    public function matchesAnyPrefix(string $path, array $prefixes): bool
    {
        $path = '/'.\ltrim($path, '/');

        foreach ($prefixes as $prefix) {
            if ($this->pathMatchesPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeTtl(mixed $ttl): int
    {
        $ttl = (int) $ttl;

        return $ttl > 0 ? \min($ttl, 86400) : self::DEFAULT_TTL;
    }

    private function pathMatchesPrefix(string $path, string $prefix): bool
    {
        $prefix = '/'.\ltrim(\rtrim($prefix, '/'), '/');
        if ($prefix === '/') {
            return false;
        }

        if ($path === $prefix || \str_starts_with($path, $prefix.'/')) {
            return true;
        }

        // Locale-prefixed front routes: /tr/hesap/...
        return (bool) \preg_match('#^/[a-z]{2,5}'.\preg_quote($prefix, '#').'(?:/|$)#', $path);
    }
}
