<?php

declare(strict_types=1);

namespace Modules\Seo\Redirect;

/**
 * Normalises the two sides of a Studio redirect.
 *
 * Source is stored without a leading slash so it matches Request::getPathInfo()
 * after ltrim. Target is either a site-relative path or an explicit http(s) URL.
 * javascript:/data:/protocol-relative values are refused — a Location header
 * is a browser redirect, not a server fetch, but those schemes are never a
 * legitimate SEO destination.
 */
final class SeoRedirectPath
{
    public static function normalizeSource(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            $parts = parse_url($raw);
            $raw = \is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        }

        $path = ltrim(str_replace('\\', '/', urldecode($raw)), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        if (\strlen($path) > 255) {
            return null;
        }

        return $path;
    }

    public static function normalizeTarget(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^(javascript|data|vbscript):#i', $raw) === 1) {
            return null;
        }

        if (str_starts_with($raw, '//')) {
            return null;
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            $parts = parse_url($raw);
            if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            $scheme = strtolower((string) $parts['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }

            return \strlen($raw) <= 500 ? $raw : null;
        }

        $path = '/'.ltrim(str_replace('\\', '/', $raw), '/');
        if (str_contains($path, '..') || str_starts_with($path, '//') || \strlen($path) > 500) {
            return null;
        }

        return $path;
    }

    public static function statusCode(string $raw): int
    {
        return $raw === (string) 302 ? 302 : 301;
    }
}
