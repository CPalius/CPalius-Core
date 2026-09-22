<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Post-login destinations must be real pages. Poll/JSON URLs are same-origin
 * but must never become _security.*.target_path — a dashboard fetch that
 * 401s would otherwise dump raw telemetry in the tab after sign-in.
 */
final class LoginTargetPath
{
    /** @var list<string> */
    private const MACHINE_PATHS = [
        '/aacp/telemetry/live-feed',
        '/aacp/system/metrics',
        '/hesap/nabiz',
    ];

    /** @var list<string> */
    private const MACHINE_PREFIXES = [
        '/_fragment',
        '/api/',
        '/_wdt',
        '/_profiler',
        '/assets/',
        '/build/',
    ];

    /** @var list<string> */
    private const MACHINE_SUFFIXES = [
        '/live-feed',
        '/nabiz',
        '/canli',
        '/metrics',
    ];

    /** @var list<string> */
    private const AUTH_DOORS = [
        '/login',
        '/hesap/giris',
        '/logout',
        '/hesap/cikis',
    ];

    public static function isNavigable(string $target): bool
    {
        $path = self::pathOf($target);
        if ($path === '' || $path === '/') {
            return $path === '/';
        }

        return !self::isAuthDoor($path) && !self::isMachinePath($path);
    }

    public static function isMachinePath(string $path): bool
    {
        $path = self::pathOf($path);
        if ($path === '') {
            return false;
        }

        if (str_ends_with($path, '.json')) {
            return true;
        }

        foreach (self::MACHINE_PATHS as $exact) {
            if ($path === $exact) {
                return true;
            }
        }

        foreach (self::MACHINE_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        foreach (self::MACHINE_SUFFIXES as $suffix) {
            if ($path === $suffix || str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * fetch() / XHR / Accept: JSON — not a document navigation.
     */
    public static function isMachineRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $dest = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));
        if ($dest === 'empty') {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }

        return self::isMachinePath($request->getPathInfo());
    }

    /**
     * Address-bar GET (or a client that prefers HTML over JSON).
     */
    public static function isBrowserDocument(Request $request): bool
    {
        $dest = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));
        if ($dest === 'document') {
            return true;
        }
        if ($dest === 'empty') {
            return false;
        }

        $accept = (string) $request->headers->get('Accept', '');
        if ($accept === '') {
            return false;
        }

        $htmlPos = stripos($accept, 'text/html');
        $jsonPos = stripos($accept, 'application/json');

        return $htmlPos !== false && ($jsonPos === false || $htmlPos < $jsonPos);
    }

    public static function pathOf(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }

        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            $query = strpos($target, '?');

            return $query === false ? $target : substr($target, 0, $query);
        }

        $parts = parse_url($target);

        return \is_array($parts) && isset($parts['path']) ? $parts['path'] : '';
    }

    private static function isAuthDoor(string $path): bool
    {
        foreach (self::AUTH_DOORS as $door) {
            if ($path === $door) {
                return true;
            }
        }

        return false;
    }
}
