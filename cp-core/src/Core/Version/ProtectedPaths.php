<?php

declare(strict_types=1);

namespace App\Core\Version;

/**
 * What a release is not allowed to write over, and where it may write at all.
 *
 * Extracted from CoreUpdater when the patch installer arrived, because two
 * copies of this rule is one copy too many. The full updater and the file-level
 * patcher both take remote input and turn it into files on this disk; a path
 * that one of them refuses and the other accepts is not an inconsistency, it is
 * a hole — and it would be the newer, less-exercised code that had it.
 */
final class ProtectedPaths
{
    /**
     * Paths no update may write to, matched against the project-relative path.
     *
     * These hold things no release can know about: the site's own media, its
     * rendered page cache, and its compiled container and logs. An archive that
     * carries them has them ignored rather than applied.
     *
     * @var list<string>
     */
    public const PROTECTED_PREFIXES = [
        'public/uploads/',
        'public/page-cache/',
        'cp-core/var/',
    ];

    /**
     * Environment files are protected by name rather than by prefix, because
     * `.env.example` must go the other way.
     *
     * `.env` and its `.local` overrides are the site's own configuration and
     * hold the database password — a release overwriting them would lock the
     * site out of its own database. `.env.example` is the opposite: it is the
     * documentation of what can be configured, it ships WITH the release, and
     * an installation that never receives an updated copy never learns that a
     * new option exists.
     */
    public const PROTECTED_ENV_EXCEPTION = '.env.example';

    /**
     * Directories a patch is allowed to touch.
     *
     * The full updater does not need this — it writes whatever a verified
     * archive contains — but a patch names individual paths, and a patch file
     * is a much smaller thing to get wrong or to forge. Restricting it to the
     * trees that ship in the repository means a manifest cannot reach into
     * cp-includes/vendor (which is not in version control and is Composer's to
     * own) or invent a path outside the project layout entirely.
     *
     * @var list<string>
     */
    public const PATCHABLE_PREFIXES = [
        'cp-core/',
        'cp-content/',
        'public/',
    ];

    /**
     * Root-level files a patch may replace. An allowlist rather than a pattern:
     * these are the only loose files the package ships, and a new one is a
     * deliberate decision rather than something a manifest should be able to
     * introduce.
     *
     * @var list<string>
     */
    public const PATCHABLE_ROOT_FILES = [
        'composer.json',
        'composer.lock',
        'symfony.lock',
        'importmap.php',
        '.env.example',
        'LICENSE',
        'index.php',
        '.htaccess',
    ];

    private function __construct()
    {
    }

    /** True when an update must leave this project-relative path alone. */
    public static function isProtected(string $relative): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        if ($relative === self::PROTECTED_ENV_EXCEPTION) {
            return false;
        }

        // Anything else named .env or .env.<something>, at any depth, is site
        // configuration and stays untouched.
        $name = basename($relative);

        return $name === '.env' || str_starts_with($name, '.env.');
    }

    /**
     * Normalises a manifest-supplied path, or returns null when it is not
     * something a patch may write.
     *
     * Fail-closed and in one place: every rejection below is a way to turn "one
     * file changed upstream" into "arbitrary write on this server".
     */
    public static function normalizePatchPath(string $raw): ?string
    {
        $path = trim(str_replace('\\', '/', $raw));

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }

        // Windows drive letters and UNC paths are absolute too, and neither
        // starts with a slash.
        if (preg_match('#^[a-zA-Z]:#', $path) === 1) {
            return null;
        }

        // Rejected as a substring rather than resolved: "a/../b" is harmless
        // and "../b" is not, and telling them apart reliably means resolving
        // against a filesystem that may not have the intermediate directories
        // yet. Refusing both costs a publisher nothing — no real path in the
        // repository contains "..".
        if (str_contains($path, '..')) {
            return null;
        }

        if (self::isProtected($path)) {
            return null;
        }

        if (in_array($path, self::PATCHABLE_ROOT_FILES, true)) {
            return $path;
        }

        foreach (self::PATCHABLE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return null;
    }
}
