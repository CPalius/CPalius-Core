<?php

declare(strict_types=1);

namespace App\Core\Routing;

/**
 * Operator-chosen addresses for Studio, the AACP and the panel sign-in:
 * CP_STUDIO_PATH, CP_AACP_PATH and CP_LOGIN_PATH in .env.
 *
 * Inside the application the paths stay /admin, /aacp and /login, so routes,
 * access_control, listeners and modules need no knowledge of the setting. Only
 * the boundary translates: the kernel on the way in, the router and the
 * response subscriber on the way out. A renamed default answers 404.
 *
 * An unusable value (bad characters, reserved or duplicate name) falls back to
 * that panel's default, so a typo cannot lock the operator out.
 */
final class PanelPaths
{
    /** env name => internal segment */
    private const KEYS = ['CP_STUDIO_PATH' => 'admin', 'CP_AACP_PATH' => 'aacp', 'CP_LOGIN_PATH' => 'login'];

    private const RESERVED = [
        'admin', 'aacp', 'login', 'logout', 'api', 'hesap', 'cron', 'uploads', 'themes', 'assets',
        'vendor', 'fonts', 'bundles', 'build', 'page-cache', 'install', 'index',
    ];

    /** Where a request for a renamed default is sent: no route lives here. */
    public const HIDDEN = '/__cp-hidden';

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string> internal segment => public segment, renamed panels only
     */
    public static function resolve(array $env): array
    {
        $map = [];
        foreach (self::KEYS as $name => $internal) {
            $value = strtolower(trim((string) ($env[$name] ?? ''), " /\t"));
            if ($value === '' || $value === $internal
                || preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $value) !== 1
                || \in_array($value, self::RESERVED, true)
                || \in_array($value, $map, true)
            ) {
                continue;
            }
            $map[$internal] = $value;
        }

        return $map;
    }

    /** @return array<string, string> */
    public static function map(): array
    {
        $env = [];
        foreach (array_keys(self::KEYS) as $name) {
            $env[$name] = (string) ($_SERVER[$name] ?? $_ENV[$name] ?? '');
        }

        return self::resolve($env);
    }

    /**
     * Internal path (optionally behind a front-controller base path) to the public one.
     *
     * @param array<string, string>|null $map
     */
    public static function toPublic(string $path, string $base = '', ?array $map = null): string
    {
        foreach ($map ?? self::map() as $internal => $public) {
            $replaced = self::swap($path, $base.'/'.$internal, $base.'/'.$public);
            if ($replaced !== null) {
                return $replaced;
            }
        }

        return $path;
    }

    /**
     * Public path to the internal one; the default address of a renamed panel becomes HIDDEN.
     *
     * @param array<string, string>|null $map
     */
    public static function toInternal(string $path, string $base = '', ?array $map = null): string
    {
        $map ??= self::map();
        foreach ($map as $internal => $public) {
            $replaced = self::swap($path, $base.'/'.$public, $base.'/'.$internal);
            if ($replaced !== null) {
                return $replaced;
            }
            if (self::swap($path, $base.'/'.$internal, '') !== null) {
                return $base.self::HIDDEN;
            }
        }

        return $path;
    }

    /** Replaces a leading prefix only on a whole segment: "/admin" never matches "/administrator". */
    private static function swap(string $path, string $from, string $to): ?string
    {
        if (!str_starts_with($path, $from)) {
            return null;
        }
        $next = $path[\strlen($from)] ?? '';

        return $next === '' || $next === '/' || $next === '?' || $next === '#' ? $to.substr($path, \strlen($from)) : null;
    }
}
