<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

/**
 * The single gate every member-supplied address passes through.
 *
 * Showcase entries are written by members and read by everyone, so a URL field is
 * a stored-XSS vector the moment it reaches an href. Rejecting anything that is
 * not http/https closes "javascript:", "data:", "vbscript:" and the whitespace-
 * and control-character tricks used to smuggle them past a naive prefix check.
 */
final class ShowcaseUrlValidator
{
    private const MAX_LENGTH = 500;

    /**
     * Returns the cleaned absolute URL, or null when it must not be stored.
     */
    public function sanitize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Strip every C0/C1 control character and the whitespace forms a browser
        // would ignore, so "java\nscript:alert(1)" cannot re-form after parsing.
        $url = preg_replace('/[\x00-\x20\x7F-\xA0]/u', '', trim($raw));

        if (!\is_string($url) || $url === '') {
            return null;
        }

        if (mb_strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    public function isValid(?string $raw): bool
    {
        return $this->sanitize($raw) !== null;
    }

    /**
     * Host shown next to an outbound link so a visitor can see where it goes
     * before clicking. Returns null when the URL is not usable.
     */
    public function displayHost(?string $url): ?string
    {
        $clean = $this->sanitize($url);

        if ($clean === null) {
            return null;
        }

        $host = parse_url($clean, \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
